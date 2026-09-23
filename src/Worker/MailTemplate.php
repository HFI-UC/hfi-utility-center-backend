<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

final class MailTemplate
{
    private const LOGO = 'https://s21.ax1x.com/2025/09/25/pV5T6mt.png';

    public static function html(
        string $title,
        string $details,
        string $studentName,
        string $studentId,
        string $className,
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
        $fields = [
            ['Student Name', $studentName],
            ['Student Class', $className === '' ? '—' : $className],
            ['Room', $room],
            ['Student ID', $studentId],
            ['Reason', $reason],
            ['Time Period', $time],
            ['Campus', $campus],
            ['Purpose', $purpose],
            ['Equipment', $needsMultimedia ? 'Multimedia equipment required' : 'No multimedia equipment'],
        ];

        return self::document($title, $details, self::grid($fields) . self::action($actionUrl));
    }

    /** @param list<array{0: string, 1: string}> $fields */
    private static function grid(array $fields): string
    {
        $rows = '';
        $count = count($fields);
        for ($index = 0; $index < $count; $index += 2) {
            $gap = $index + 2 < $count ? 'padding-bottom:15px;' : '';
            $left = $fields[$index];
            $right = $fields[$index + 1] ?? null;
            $rows .= '<tr><td width="50%" valign="top" style="' . $gap . 'padding-right:10px;">'
                . self::field($left[0], $left[1])
                . '</td><td width="50%" valign="top" style="' . $gap . 'padding-left:10px;">'
                . ($right === null ? '' : self::field($right[0], $right[1]))
                . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="background-color:#F6F6F6;padding:30px 40px;border-radius:12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;">'
            . $rows
            . '</table></td></tr></table>';
    }

    private static function field(string $label, string $value): string
    {
        return '<p style="margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-size:16px;color:#333333;text-align:left;">'
            . self::escape($label)
            . '</p><p style="margin:5px 0 0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-size:16px;color:#787878;text-align:left;">'
            . self::escape($value)
            . '</p>';
    }

    private static function action(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px;"><tr><td style="background-color:#F97316;border-radius:15px;padding:10px 16px;"><a href="'
            . self::escape($url)
            . '" target="_blank" style="display:block;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:24px;font-weight:700;font-size:15px;color:#FFFFFF;text-decoration:none;text-align:center;">Manage reservation</a></td></tr></table>'
            . '<p style="margin:12px 0 0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:20px;font-size:13px;color:#787878;">This private link lets you modify or cancel before the reservation begins.</p>';
    }

    private static function document(string $title, string $details, string $body): string
    {
        $text = 'margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;';

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width"><title>'
            . self::escape($title)
            . '</title></head><body style="min-width:100%;margin:0;padding:0;background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center" style="padding:50px 16px;background-color:#FFFFFF;"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;"><tr><td style="border:1px solid #EBEBEB;background-color:#FFFFFF;padding:44px 42px 32px 42px;border-radius:25px;"><img src="'
            . self::LOGO
            . '" width="45" height="45" alt="HFI-UC" style="display:block;border:0;width:45px;height:45px;"><div style="line-height:42px;font-size:1px;">&nbsp;</div><h1 style="'
            . $text
            . 'padding:0 0 18px 0;border-bottom:1px solid #EFF1F4;line-height:28px;font-weight:700;font-size:24px;letter-spacing:-1px;color:#141414;text-align:left;">'
            . self::escape($title)
            . '</h1><p style="'
            . $text
            . 'padding-top:18px;line-height:25px;font-weight:400;font-size:15px;letter-spacing:-0.1px;color:#141414;text-align:left;">'
            . self::escape($details)
            . '</p><div style="line-height:18px;font-size:1px;">&nbsp;</div>'
            . $body
            . '<div style="line-height:25px;font-size:1px;">&nbsp;</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="background-color:#F97316;border-radius:12px;"><p style="'
            . $text
            . 'line-height:70px;font-weight:400;font-size:16px;color:#FFFFFF;text-align:center;">Copyright © 2025 MAKERs&#39;.</p></td></tr></table></td></tr></table></td></tr></table></body></html>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
