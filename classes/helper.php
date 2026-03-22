<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace qbank_duplicatefinder;

/**
 * Helper class for duplicate question detection.
 *
 * @package    qbank_duplicatefinder
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** Maximum questions to compare (O(n²) protection). */
    const MAX_QUESTIONS = 500;

    /**
     * Load one representative question per question bank entry from the given category IDs.
     *
     * Moodle's versioning model:
     *   question_bank_entries  (one logical question)
     *       └── question_versions  (v1, v2, v3 … each pointing to a {question} row)
     *
     * We select exactly one row per question_bank_entry — the highest-numbered
     * version that has status 'ready'.  This means:
     *  - Multiple versions of the same question are never compared against each other.
     *  - Only the content that is currently "live" is considered.
     *
     * Returns stdClass objects with:
     *   id, name, questiontext, qtype,
     *   questionbankentryid, currentversion,
     *   categoryid, categoryname
     *
     * @param int[] $categoryids
     * @return \stdClass[]
     */
    public static function load_questions(array $categoryids): array {
        global $DB;

        if (empty($categoryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);

        // The subquery picks the maximum ready version for each bank entry.
        // Questions with no ready version at all are excluded by the correlated
        // subquery returning NULL (the outer WHERE fails the equality check).
        $sql = "SELECT q.id,
                       q.name,
                       q.questiontext,
                       q.qtype,
                       qbe.id   AS questionbankentryid,
                       qv.version AS currentversion,
                       qbe.questioncategoryid AS categoryid,
                       qc.name  AS categoryname
                  FROM {question} q
                  JOIN {question_versions} qv
                    ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe
                    ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc
                    ON qc.id = qbe.questioncategoryid
                 WHERE qbe.questioncategoryid $insql
                   AND q.qtype <> 'random'
                   AND qv.version = (
                           SELECT MAX(qv2.version)
                             FROM {question_versions} qv2
                            WHERE qv2.questionbankentryid = qbe.id
                              AND qv2.status = 'ready'
                       )
              ORDER BY qbe.id";

        // Key the recordset on questionbankentryid so that even if the DB
        // returns a duplicate row the array stays deduplicated by bank entry.
        $records = $DB->get_records_sql($sql, $params);
        $keyed = [];
        foreach ($records as $row) {
            $keyed[$row->questionbankentryid] = $row;
        }

        return array_values($keyed);
    }

    /**
     * Normalise question text for comparison.
     *
     * Strips HTML tags, decodes entities, lowercases, and collapses whitespace
     * so that superficial formatting differences don't inflate the difference score.
     *
     * @param string $text Raw question text (may contain HTML).
     * @return string Normalised plain text.
     */
    public static function normalise(string $text): string {
        // Strip HTML tags.
        $text = strip_tags($text);
        // Decode HTML entities (e.g. &amp; → &).
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Lowercase.
        $text = mb_strtolower($text, 'UTF-8');
        // Collapse all whitespace (including newlines) to a single space.
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Calculate the similarity percentage between two normalised strings.
     *
     * Uses PHP's built-in similar_text() which implements an algorithm based on
     * the longest common substring approach.  The result is a float 0–100.
     *
     * @param string $a
     * @param string $b
     * @return float Similarity percentage (0–100).
     */
    public static function similarity(string $a, string $b): float {
        if ($a === '' && $b === '') {
            return 100.0;
        }
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $percent);
        return round($percent, 1);
    }

    /**
     * Find groups of potential duplicate questions.
     *
     * Compares every pair of questions (up to MAX_QUESTIONS) and groups those
     * whose similarity meets or exceeds $threshold into "duplicate clusters"
     * using a simple union-find approach.
     *
     * @param \stdClass[] $questions   Flat list of question records.
     * @param float       $threshold   Minimum similarity % to flag as duplicate (0–100).
     * @return array[] Each element is an array of ['question' => stdClass, 'similarity' => float|null].
     *                 The first member of each group has similarity === null (it is the reference).
     */
    public static function find_duplicate_groups(array $questions, float $threshold): array {
        $count = count($questions);
        if ($count < 2) {
            return [];
        }

        // Precompute normalised texts.
        $normalised = [];
        foreach ($questions as $i => $q) {
            $normalised[$i] = self::normalise($q->questiontext);
        }

        // Parent[i] = i means i is a group root.
        $parent = range(0, $count - 1);

        $find = function (int $x) use (&$parent, &$find): int {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };

        $union = function (int $x, int $y) use (&$parent, $find): void {
            $rx = $find($x);
            $ry = $find($y);
            if ($rx === $ry) {
                return;
            }
            // Merge smaller index into larger to keep lower IDs as roots.
            if ($rx < $ry) {
                $parent[$ry] = $rx;
            } else {
                $parent[$rx] = $ry;
            }
        };

        // O(n²) pairwise comparison — store scores for reuse when building output.
        $scores = [];
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sim = self::similarity($normalised[$i], $normalised[$j]);
                $scores[$i][$j] = $sim;
                if ($sim >= $threshold) {
                    $union($i, $j);
                }
            }
        }

        // Collect groups: root → [members].
        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            $groups[$root][] = $i;
        }

        // Build output: only groups with more than one member.
        $result = [];
        foreach ($groups as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            $group = [];
            foreach ($members as $idx) {
                if ($idx === $root) {
                    $sim = null;
                } else {
                    $sim = $idx > $root ? $scores[$root][$idx] : $scores[$idx][$root];
                }
                $group[] = [
                    'question'   => $questions[$idx],
                    'similarity' => $sim,
                ];
            }
            // Sort group: root first (similarity null), then descending similarity.
            usort($group, function ($a, $b) {
                if ($a['similarity'] === null) {
                    return -1;
                }
                if ($b['similarity'] === null) {
                    return 1;
                }
                return $b['similarity'] <=> $a['similarity'];
            });
            $result[] = $group;
        }

        return $result;
    }

    /**
     * Return all descendant category IDs for a given category (inclusive).
     *
     * @param int $categoryid
     * @return int[]
     */
    public static function get_category_ids_recursive(int $categoryid): array {
        global $DB;

        $ids   = [$categoryid];
        $queue = [$categoryid];

        while (!empty($queue)) {
            [$insql, $params] = $DB->get_in_or_equal($queue, SQL_PARAMS_NAMED);
            $children = $DB->get_fieldset_select('question_categories', 'id', "parent $insql", $params);
            $queue = array_diff($children, $ids);
            $ids   = array_merge($ids, $children);
        }

        return array_unique($ids);
    }

    /**
     * Return all category IDs visible in the given context.
     *
     * @param \context $context
     * @return int[]
     */
    public static function get_context_category_ids(\context $context): array {
        global $DB;
        return $DB->get_fieldset_select('question_categories', 'id', 'contextid = :ctxid', ['ctxid' => $context->id]);
    }

    /**
     * Return a rendered Bootstrap badge with an icon for the given similarity score.
     *
     * @param float  $sim  Similarity score 0-100.
     * @param string $text Label text to display inside the badge.
     * @return string HTML span element.
     */
    public static function similarity_badge(float $sim, string $text): string {
        if ($sim >= 95) {
            $class = 'badge bg-danger';
            $icon  = 'fa fa-circle-exclamation';
        } else if ($sim >= 80) {
            $class = 'badge bg-warning text-dark';
            $icon  = 'fa fa-triangle-exclamation';
        } else {
            $class = 'badge bg-info text-dark';
            $icon  = 'fa fa-circle-info';
        }

        $iconhtml = \html_writer::tag('i', '', ['class' => $icon . ' me-1', 'aria-hidden' => 'true']);
        return \html_writer::tag('span', $iconhtml . $text, ['class' => $class, 'style' => 'font-size:1.1em;']);
    }
}
