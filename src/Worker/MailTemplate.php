<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

final class MailTemplate
{
    public static function html(
        string $title,
        string $details,
        string $studentName,
        string $studentId,
        string $room,
        string $campus,
        string $reason,
        string $purpose,
        bool $needsMultimedia,
        string $start,
        string $end,
        ?string $actionUrl,
    ): string {
        $time = substr(str_replace('T', ' ', $start), 0, 16) . ' – ' . substr($end, 11, 5);
        $person = $studentName . ' · ' . $studentId;
        $place = $room . ' · ' . $campus;
        $equipment = $needsMultimedia ? 'Multimedia equipment required' : 'No multimedia equipment';
        $cards = self::row(self::card('&#9673;', 'Location', $place) . self::card('&#9719;', 'Date & time', $time))
            . self::row(self::card('&#9786;', 'Reserved by', $person) . self::card('&#9670;', 'Purpose', $purpose))
            . self::row(self::card('&#9638;', 'Equipment', $equipment) . self::card('&#9998;', 'Reason', $reason));
        $lower = strtolower($title);
        $approved = str_contains($lower, 'approved');
        $rejected = str_contains($lower, 'rejected') || str_contains($lower, 'cancelled');
        $accent = $approved ? '#0f9f6e' : ($rejected ? '#c43b32' : '#ff5a36');
        $accentSoft = $approved ? '#e7f7f1' : ($rejected ? '#fceceb' : '#fff0eb');
        $icon = $approved ? '&#10003;' : ($rejected ? '&#10005;' : '&#9719;');
        $action = '';
        if ($actionUrl !== null) {
            $action = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px"><tr><td align="center"><a href="'
                . self::escape($actionUrl)
                . '" target="_blank" style="display:block;padding:16px 24px;background:#ff5a36;color:#ffffff;text-decoration:none;font-size:14px;font-weight:750;border-radius:14px">Manage reservation&nbsp;&nbsp;→</a></td></tr></table>'
                . '<p style="margin:12px 6px 0;color:#74777f;font-size:11px;line-height:17px;text-align:center">This private link lets you modify or cancel before the reservation begins.</p>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
            . self::escape($title)
            . '</title></head><body style="margin:0;padding:0;background:#f7f7fb;font-family:Inter,Roboto,Arial,sans-serif;color:#1b1b1f"><table role="presentation" width="100%"><tr><td align="center" style="padding:48px 16px"><table role="presentation" width="100%" style="max-width:640px;background:#ffffff;border:1px solid #e4e3e8;border-radius:28px"><tr><td style="height:6px;background:'
            . $accent
            . '">&nbsp;</td></tr><tr><td style="padding:32px 38px 30px"><strong>HFI Utility Center</strong><div style="margin-top:32px"><span style="display:inline-block;width:48px;height:48px;line-height:48px;background:'
            . $accentSoft
            . ';color:'
            . $accent
            . ';border-radius:16px;text-align:center;font-size:24px">'
            . $icon
            . '</span><h1 style="margin:18px 0 0;font-size:28px">'
            . self::escape($title)
            . '</h1><p style="margin:10px 0 0;color:#5f6068">'
            . self::escape($details)
            . '</p></div><table role="presentation" width="100%" style="margin-top:24px">'
            . $cards
            . '</table>'
            . $action
            . '<div style="margin-top:30px;color:#85858e;font-size:11px;text-align:center">© MAKERs&#39; Club 2026</div></td></tr></table></td></tr></table></body></html>';
    }

    private static function row(string $cells): string
    {
        return '<tr>' . $cells . '</tr>';
    }

    private static function card(string $icon, string $label, string $value): string
    {
        return '<td width="50%" style="padding:6px;vertical-align:top"><strong>'
            . $icon . ' ' . self::escape($label) . '</strong><div>' . self::escape($value) . '</div></td>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
