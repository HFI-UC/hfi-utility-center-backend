<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

use Hfiuc\Config;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Log\Logger;
use Hfiuc\Support\Clock;
use Hfiuc\Support\Token;
use PHPMailer\PHPMailer\PHPMailer;

final class OutboxWorker
{
    private const AI_APPROVAL_PROMPT = <<<'PROMPT'
You are a strict, automated classroom reservation approval agent. Your sole task is to decide whether to approve a reservation based on a "reason" string.

Follow these rules in order. Apply the first rule that matches. All matching is case-insensitive.

1.  **Prohibited Content**: If the reason mentions threats, violence, illegal acts, or hate speech, reject with the message: "Request involves prohibited content."
2.  **Gaming**: If the reason is for game-related activities (e.g., "video games", "board games", "game night"), reject with the message: "Game-related activities are not permitted."
3.  **Social Events**: If the reason is for a private social event (e.g., "birthday party", "private gathering"), reject with the message: "Social-only events are not permitted."
4.  **Club Specificity**:
    - A specific club name is usually a proper noun, an acronym, or a phrase ending in "Club", "Society", "Team", or "Association" (e.g., "Chess Club", "ACGN Club", "Robotics Team").
    - If the reason contains a specific club name, proceed to rule 5.
    - If it mentions a generic club (e.g., "club meeting", "student club"), reject with the message: "Please provide the specific club name."
5.  **Allowed Purposes**: If the reason clearly describes an academic, instructional, cultural, or civic event, or a valid club activity (from rule 4), approve. The message must be null.

**Output Constraints:**
- If `status` is `approved`, `message` MUST be `null`.
- If `status` is `rejected`, `message` MUST be one of the exact strings provided in the rules.
- Do not add any commentary or explanations outside the JSON structure.

**Examples:**
- "Chess Club meeting - weekly practice" -> {"status": "approved", "message": null}
- "ACGN club activity" -> {"status": "approved", "message": null}
- "Board game night" -> {"status": "rejected", "message": "Game-related activities are not permitted."}
- "Club meeting" -> {"status": "rejected", "message": "Please provide the specific club name."}
- "Guest lecture on climate policy" -> {"status": "approved", "message": null}
- "Birthday party for student" -> {"status": "rejected", "message": "Social-only events are not permitted."}
- "Study session" -> {"status": "approved", "message": null}
PROMPT;

    private const AI_REJECTION_MESSAGES = [
        'Request involves prohibited content.',
        'Game-related activities are not permitted.',
        'Social-only events are not permitted.',
        'Please provide the specific club name.',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Outbox $outbox,
    ) {
    }

    public function processQueuedJob(int $jobId, ?string $dispatchToken = null): void
    {
        $existing = $this->db->fetch('SELECT id, status, lockedAt, dispatchToken, availableAt FROM outboxjob WHERE id = ?', [$jobId]);
        if ($existing === null) {
            throw new HttpException(404, 'Outbox job not found.');
        }
        $status = (string) $existing['status'];
        if ($status === 'completed' || $status === 'failed') {
            return;
        }
        if ($dispatchToken !== null && !hash_equals((string) ($existing['dispatchToken'] ?? ''), $dispatchToken)) {
            // A newer dispatch lease superseded this Queue delivery.
            return;
        }
        if ($this->db->fetch('SELECT id FROM outboxjob WHERE id = ? AND availableAt <= NOW()', [$jobId]) === null) {
            return;
        }
        if ($status === 'processing' && $existing['lockedAt'] !== null) {
            $lockedAt = Clock::parseSql((string) $existing['lockedAt']);
            if ($lockedAt > Clock::now()->modify('-2 minutes')) {
                throw new HttpException(409, 'Outbox job is already being processed.');
            }
        }
        // outboxjob.lockToken is CHAR(36).
        $token = substr(Token::random(), 0, 36);
        $claimSql = 'UPDATE outboxjob SET status = \'processing\', lockedAt = NOW(), attempts = attempts + 1, lockToken = ? WHERE id = ? AND availableAt <= NOW()'
            . ' AND (status IN (\'pending\', \'leased\') OR (status = \'processing\' AND (lockedAt IS NULL OR lockedAt <= DATE_SUB(NOW(), INTERVAL 2 MINUTE))))';
        $params = [$token, $jobId];
        if ($dispatchToken !== null) {
            $claimSql .= ' AND dispatchToken = ?';
            $params[] = $dispatchToken;
        }
        $claimed = $this->db->execute($claimSql, $params);
        if ($claimed !== 1) {
            throw new HttpException(409, 'Outbox job is already being processed.');
        }
        $job = $this->db->fetch('SELECT id, kind, payload, attempts FROM outboxjob WHERE id = ? AND lockToken = ?', [$jobId, $token]);
        if ($job === null) {
            throw new HttpException(409, 'Outbox job is already being processed.');
        }
        $payload = json_decode((string) $job['payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        try {
            $this->dispatch((string) $job['kind'], $payload);
            $completed = $this->db->execute(
                'UPDATE outboxjob SET status = \'completed\', completedAt = NOW() WHERE id = ? AND lockToken = ?',
                [$jobId, $token],
            );
            if ($completed !== 1) {
                return;
            }
            $this->logger->audit('worker.completed', 'outboxjob', $jobId, ['kind' => $job['kind']]);
        } catch (\Throwable $error) {
            $attempts = (int) $job['attempts'];
            $nextStatus = (string) $job['kind'] === 'ai_approval' || $attempts < 8 ? 'pending' : 'failed';
            $delay = min(1800, max(30, (2 ** min($attempts, 8)) * 10));
            $this->db->execute(
                'UPDATE outboxjob SET status = ?, lastError = ?, lockToken = NULL, dispatchToken = NULL, leaseUntil = NULL, availableAt = DATE_ADD(NOW(), INTERVAL ' . $delay . ' SECOND) WHERE id = ? AND lockToken = ?',
                [$nextStatus, substr($error->getMessage(), 0, 2000), $jobId, $token],
            );
            $this->logger->error('Outbox job failed', [
                'id' => $jobId,
                'kind' => $job['kind'],
                'attempts' => $attempts,
                'error' => $error->getMessage(),
            ]);
            $this->logger->audit('worker.failed', 'outboxjob', $jobId, ['kind' => $job['kind'], 'status' => $nextStatus]);
            if ($nextStatus === 'failed') {
                return;
            }
            throw new HttpException(500, 'Outbox job failed.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(string $kind, array $payload): void
    {
        if ($kind === 'ai_approval') {
            $this->aiApproval($payload);

            return;
        }
        if (in_array($kind, ['reservation_created', 'reservation_modified', 'reservation_cancelled', 'reservation_status_changed'], true)) {
            $this->sendJobEmail($kind, $payload);

            return;
        }
        if ($kind === 'admin_reservation_notification') {
            $this->sendAdminNotification($payload);

            return;
        }
        throw new \RuntimeException('Unsupported outbox task kind: ' . $kind);
    }

    /** @param array<string, mixed> $payload */
    private function sendJobEmail(string $kind, array $payload): void
    {
        if ($this->config->smtpServer === '' || $this->config->smtpEmail === '') {
            return;
        }
        $id = (int) ($payload['reservationId'] ?? 0);
        $reservation = isset($payload['snapshot']) && is_array($payload['snapshot'])
            ? $payload['snapshot']
            : $this->db->fetch(
                'SELECT r.email, r.studentName, r.reason, r.status, r.startTime, r.endTime, r.purposeType, r.needsMultimedia, rm.name AS room_name, c.name AS class_name, cp.name AS campus_name FROM reservation r LEFT JOIN room rm ON rm.id = r.roomId LEFT JOIN class c ON c.id = r.classId LEFT JOIN campus cp ON cp.id = rm.campusId WHERE r.id = ?',
                [$id],
            );
        if ($reservation === null || (string) $reservation['email'] === '') {
            return;
        }
        $status = isset($payload['status']) && is_string($payload['status']) ? $payload['status'] : (string) $reservation['status'];
        [$subject, $title, $details] = $this->copy($kind, $payload, $status);
        $token = isset($payload['cancelToken']) && is_string($payload['cancelToken']) ? $payload['cancelToken'] : null;
        $action = $token === null ? null : rtrim($this->config->frontendUrl, '/') . '/reservation/cancel?token=' . rawurlencode($token);
        $start = (string) $reservation['startTime'];
        $end = (string) $reservation['endTime'];
        $html = MailTemplate::html(
            $title,
            $details,
            (string) $reservation['studentName'],
            $reservation['class_name'] === null ? '—' : (string) $reservation['class_name'],
            $reservation['room_name'] === null ? '—' : (string) $reservation['room_name'],
            $reservation['campus_name'] === null ? '—' : (string) $reservation['campus_name'],
            (string) $reservation['reason'],
            $reservation['purposeType'] === null ? '—' : (string) $reservation['purposeType'],
            $reservation['needsMultimedia'] === 1 || $reservation['needsMultimedia'] === '1',
            str_replace(' ', 'T', $start),
            str_replace(' ', 'T', $end),
            $action,
        );
        $this->send((string) $reservation['email'], '[HFI-UC] ' . $subject, $html);
    }

    /** @param array<string, mixed> $payload
     * @return array{0: string, 1: string, 2: string}
     */
    private function copy(string $kind, array $payload, string $status): array
    {
        if ($kind === 'reservation_created') {
            return ['Reservation Created', 'Your reservation has been created', 'We received your reservation request. You can review the details below.'];
        }
        if ($kind === 'reservation_modified') {
            return ['Reservation Modified', 'Your reservation has been updated', 'The reservation details were changed successfully. Please review the latest room and time below.'];
        }
        if ($kind === 'reservation_cancelled' && ($payload['reason'] ?? null) === 'higher_priority') {
            return ['Reservation Cancelled', 'Your reservation was cancelled', 'A higher-priority administrator reservation requires this room and time. Your original reservation has been cancelled automatically, and the time is no longer available.'];
        }
        if ($kind === 'reservation_cancelled') {
            return ['Reservation Cancelled', 'Your reservation has been cancelled', 'This reservation is no longer active. The released time is available for booking again.'];
        }
        if ($status === 'approved') {
            return ['Reservation Approved', 'Your reservation has been approved', 'Your reservation request was approved. Please arrive on time and follow the room rules.'];
        }
        if ($status === 'rejected') {
            return ['Reservation Rejected', 'Your reservation was not approved', 'Your reservation request was reviewed but could not be approved.'];
        }

        return ['Reservation Updated', 'Your reservation has been updated', 'The status of your reservation changed. The latest details are shown below.'];
    }

    /** @param array<string, mixed> $payload */
    private function sendAdminNotification(array $payload): void
    {
        if ($this->config->smtpServer === '' || $this->config->smtpEmail === '') {
            return;
        }
        $reservationId = (int) ($payload['reservationId'] ?? 0);
        $adminId = (int) ($payload['adminId'] ?? 0);
        $row = $this->db->fetch(
            'SELECT a.email AS admin_email, r.studentName, r.reason, r.startTime, r.endTime, r.purposeType, r.needsMultimedia, rm.name AS room_name, c.name AS class_name, cp.name AS campus_name FROM admin a CROSS JOIN reservation r LEFT JOIN room rm ON rm.id = r.roomId LEFT JOIN class c ON c.id = r.classId LEFT JOIN campus cp ON cp.id = rm.campusId WHERE a.id = ? AND a.receiveReservationNotifications = 1 AND r.id = ?',
            [$adminId, $reservationId],
        );
        if ($row === null) {
            return;
        }
        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            $row = array_replace($row, $payload['snapshot']);
        }
        $html = MailTemplate::html(
            'New reservation awaiting review',
            'A new reservation was submitted. Administrators who manage this room can review it in the management platform.',
            (string) $row['studentName'],
            $row['class_name'] === null ? '—' : (string) $row['class_name'],
            $row['room_name'] === null ? '—' : (string) $row['room_name'],
            $row['campus_name'] === null ? '—' : (string) $row['campus_name'],
            (string) $row['reason'],
            $row['purposeType'] === null ? '—' : (string) $row['purposeType'],
            $row['needsMultimedia'] === 1 || $row['needsMultimedia'] === '1',
            str_replace(' ', 'T', (string) $row['startTime']),
            str_replace(' ', 'T', (string) $row['endTime']),
            null,
        );
        $this->send((string) $row['admin_email'], '[HFI-UC] New reservation awaiting review', $html);
    }

    /** @param array<string, mixed> $payload */
    private function aiApproval(array $payload): void
    {
        if (!$this->config->aiEnabled) {
            return;
        }
        $id = (int) ($payload['reservationId'] ?? 0);
        $version = (int) ($payload['reviewVersion'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('AI approval job has no reservation ID');
        }
        $row = $this->db->transaction(function () use ($id, $version): ?array {
            $row = $this->db->fetch('SELECT reason, status, reviewVersion FROM reservation WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null || (int) $row['reviewVersion'] !== $version) {
                return null;
            }
            if ((string) $row['status'] === 'pending') {
                $this->db->execute('UPDATE reservation SET status = \'ai_reviewing\' WHERE id = ? AND status = \'pending\'', [$id]);
            } elseif ((string) $row['status'] !== 'ai_reviewing') {
                return null;
            }

            return $row;
        });
        if ($row === null) {
            return;
        }
        $body = $this->requestAi((string) $row['reason']);
        $status = (string) ($body['status'] ?? '');
        if ($status === 'pending') {
            throw new \RuntimeException('AI approval is still pending');
        }
        if ($status !== 'approved' && $status !== 'rejected') {
            throw new \RuntimeException('AI approval service returned an unsupported status');
        }
        $this->db->transaction(function () use ($id, $version, $status, $body): void {
            $changed = $this->db->fetch(
                'SELECT startTime FROM reservation WHERE id = ? AND status = \'ai_reviewing\' AND reviewVersion = ? FOR UPDATE',
                [$id, $version],
            );
            if ($changed === null) {
                return;
            }
            $this->db->execute(
                'UPDATE reservation SET status = ?, latestExecutorId = ? WHERE id = ? AND status = \'ai_reviewing\' AND reviewVersion = ?',
                [$status, $this->config->aiAdminId > 0 ? $this->config->aiAdminId : null, $id, $version],
            );
            $raw = null;
            if ($status === 'approved') {
                $raw = Token::random();
                $this->db->execute(
                    'INSERT INTO reservationcanceltoken (reservationId, tokenHash, expiresAt) VALUES (?, ?, ?)',
                    [$id, Token::hash($raw), (string) $changed['startTime']],
                );
            }
            $this->outbox->enqueue('reservation_status_changed', [
                'reservationId' => $id,
                'status' => $status,
                'cancelToken' => $raw,
            ]);
            $this->db->execute(
                'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, ?, ?)',
                [$this->config->aiAdminId > 0 ? $this->config->aiAdminId : null, $id, $status, $body['message'] ?? null],
            );
        });
    }

    /** @return array<string, mixed> */
    private function requestAi(string $reason): array
    {
        $baseUrl = $this->config->aiApiBaseUrl;
        $model = $this->config->aiModel;
        if ($this->config->aiApiKey === '') {
            throw new \RuntimeException('Gemini API key is not configured');
        }
        if ($model === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $model) !== 1) {
            throw new \RuntimeException('Gemini model is invalid');
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new \RuntimeException('Gemini API URL is invalid');
        }
        if ($parts['scheme'] !== 'https' && !in_array($parts['host'], ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Gemini API URL must use HTTPS');
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $basePath = $query === '' ? $baseUrl : substr($baseUrl, 0, -strlen($query));
        $url = rtrim($basePath, '/') . '/' . rawurlencode($model) . ':generateContent' . $query;
        $request = json_encode([
            'systemInstruction' => ['parts' => [['text' => self::AI_APPROVAL_PROMPT]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $reason]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => ['thinkingLevel' => 'low'],
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'status' => ['type' => 'STRING', 'enum' => ['approved', 'rejected', 'pending']],
                        'message' => ['type' => 'STRING', 'nullable' => true],
                    ],
                    'required' => ['status', 'message'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($request === false) {
            throw new \RuntimeException('Unable to encode Gemini request');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to open Gemini request');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->config->aiApiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if ($curlError !== 0) {
            throw new \RuntimeException('Gemini cURL ' . $curlError . ': ' . curl_strerror($curlError));
        }
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Gemini API returned HTTP ' . $status);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini API returned invalid JSON');
        }
        $finishReason = $decoded['candidates'][0]['finishReason'] ?? null;
        if ($finishReason !== 'STOP') {
            throw new \RuntimeException('Gemini API did not finish normally: ' . (is_string($finishReason) ? $finishReason : 'missing'));
        }
        $parts = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new \RuntimeException('Gemini API returned no candidate text');
        }
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
        $decision = json_decode($text, true);
        if (!is_array($decision) || !array_key_exists('status', $decision) || !array_key_exists('message', $decision)) {
            throw new \RuntimeException('Gemini API returned invalid decision');
        }
        $decisionStatus = $decision['status'];
        $message = $decision['message'];
        if ($decisionStatus === 'approved' && $message === null) {
            return ['status' => 'approved', 'message' => null];
        }
        if ($decisionStatus === 'pending' && ($message === null || is_string($message))) {
            return ['status' => 'pending', 'message' => $message];
        }
        if ($decisionStatus === 'rejected' && is_string($message) && in_array($message, self::AI_REJECTION_MESSAGES, true)) {
            return ['status' => 'rejected', 'message' => $message];
        }
        throw new \RuntimeException('Gemini API returned invalid decision');
    }

    private function send(string $to, string $subject, string $html): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->smtpServer;
        $mail->SMTPAuth = true;
        $mail->Timeout = 15;
        $mail->Username = $this->config->smtpEmail;
        $mail->Password = $this->config->smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $this->config->smtpPort;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config->smtpEmail, 'HFI-UC');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->send();
    }
}
