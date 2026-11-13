<?php

namespace App\Support;

use App\Models\Project;

/**
 * Suggests a project code from a project name, the way the client's own codes are made:
 * BWG-<place>[-<type>]-<year>-<number>, e.g. "Kinondoni residence" → BWG-KND-RES-2026-001.
 *
 * - <place>: an acronym kept as it is (KICD, TTCL), otherwise three letters from the first word: its first letter
 *   then its next different consonants (Mwenge → MWN, Kinondoni → KND, Bandari → BND). A second place word adds
 *   its first letter instead of the third when those letters are already another project's (Mbezi residence is MBZ,
 *   so Mbezi Makonde → MBM; Mikocheni → MKM for Mikocheni Majilis) or the word is too short (Coco Beach → COB).
 * - <type>: the kind of work when the name says it (residence → RES, finishing → FIN, apartments → APT, ...).
 * - The client name "Galco" and filler words (project, site, gate, by, ...) are left out.
 * - <number>: the next free number for the same prefix and year.
 */
class ProjectCode
{
    public const TYPES = [
        'RESIDENCE' => 'RES', 'RESIDENTIAL' => 'RES', 'HOUSE' => 'RES', 'VILLA' => 'RES', 'FINISHING' => 'FIN', 'FINISH' => 'FIN',
        'APARTMENT' => 'APT', 'APARTMENTS' => 'APT', 'COMMERCIAL' => 'COM', 'DUPLEX' => 'DPX', 'LABOUR' => 'LAB',
        'RENOVATION' => 'REN', 'PLUMBING' => 'MEP', 'ELECTRICAL' => 'MEP',
    ];

    public const FILLER = ['GALCO', 'PROJECT', 'SITE', 'GATE', 'THE', 'BY', 'AND', 'OF', 'FOR', 'AT', 'WORKS', 'WORK', 'CONTRACT', 'BUILD', 'BUILDING', 'NEW', 'HOTEL', 'FIT', 'OUT'];

    public static function suggest(string $name, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $taken = Project::query()->pluck('project_code')->map(fn (string $code) => explode('-', $code)[1] ?? '')->filter()->unique()->values()->all();
        $prefix = 'BWG-'.self::middle($name, $taken).'-'.$year.'-';
        $used = Project::query()->where('project_code', 'like', $prefix.'%')->pluck('project_code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($used + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The part between BWG- and the year, e.g. "KND-RES".
     *
     * @param  list<string>  $taken  place letters other projects already use (KND, MKC, ...)
     */
    public static function middle(string $name, array $taken = []): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches);
        $words = $matches[0];
        $type = null;
        $places = [];
        foreach ($words as $word) {
            $upper = mb_strtoupper($word);
            if (isset(self::TYPES[$upper])) {
                $type ??= self::TYPES[$upper];
            } elseif (! in_array($upper, self::FILLER, true)) {
                $places[] = $word;
            }
        }
        if ($places === []) {
            return $type ?? 'PRJ';
        }
        $first = $places[0];
        $acronym = mb_strtoupper($first) === $first && mb_strlen($first) >= 2 && mb_strlen($first) <= 5;
        $place = $acronym ? self::ascii($first) : self::letters($first);
        $second = isset($places[1]) && ! $acronym ? substr(self::ascii($places[1]), 0, 1) : '';
        // A short word borrows from the next one; a place another project already uses is told apart by the next word.
        // A place already used is only told apart when the name has no type of work (the type and number do it otherwise).
        $clash = $type === null && in_array($place, $taken, true);
        if ($second !== '' && (strlen($place) < 3 || $clash)) {
            $place = substr($place, 0, 2).$second;
        } elseif (! $acronym && $clash) {
            // No second word: another letter of the same word (Mbezi is MBZ, so Mbozi → MBO).
            foreach (str_split(substr(self::ascii($first), 2)) as $letter) {
                if (! in_array(substr($place, 0, 2).$letter, $taken, true)) {
                    $place = substr($place, 0, 2).$letter;
                    break;
                }
            }
        }

        return $place.($type ? '-'.$type : '');
    }

    /** First letter, then the next different consonants, up to three letters (vowels fill in for very short words). */
    private static function letters(string $word): string
    {
        $word = self::ascii($word);
        $code = substr($word, 0, 1);
        foreach (str_split(substr($word, 1)) as $letter) {
            if (strlen($code) === 3) {
                break;
            }
            if (! str_contains('AEIOU', $letter) && ! str_contains($code, $letter)) {
                $code .= $letter;
            }
        }
        foreach (str_split(substr($word, 1)) as $letter) {
            if (strlen($code) >= 3) {
                break;
            }
            if (! str_contains($code, $letter)) {
                $code .= $letter;
            }
        }

        return $code;
    }

    private static function ascii(string $word): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word)));
    }
}
