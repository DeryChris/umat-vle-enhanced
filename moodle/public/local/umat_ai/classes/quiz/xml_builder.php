<?php
/**
 * Converts AI-generated JSON questions into strict Moodle XML format
 * suitable for import via qformat_xml.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\quiz;

defined('MOODLE_INTERNAL') || die();

class xml_builder {

    /**
     * Build a Moodle XML string from an array of AI-generated question objects.
     *
     * Expected question schema:
     *   {
     *     type: "multichoice"|"truefalse"|"shortanswer",
     *     question_text: string,
     *     options: string[],            // multichoice (4) or truefalse (2)
     *     correct_answer_index: int,    // 0-based for multichoice/truefalse
     *     correct_text: string|null,    // shortanswer only
     *     feedback_correct: string,
     *     feedback_incorrect: string,
     *     source_reference: string,
     *   }
     *
     * @param array  $questions     Array of question objects from the AI.
     * @param string $category_name Moodle question category name.
     * @return string Well-formed Moodle XML string.
     */
    public static function build_moodle_xml(array $questions, string $category_name): string {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $quiz = $dom->createElement('quiz');
        $dom->appendChild($quiz);

        // ── Category element ──
        $cat = $dom->createElement('question');
        $cat->setAttribute('type', 'category');
        $catCat = $dom->createElement('category');
        $catText = $dom->createElement('text', htmlspecialchars($category_name, ENT_XML1, 'UTF-8'));
        $catCat->appendChild($catText);
        $cat->appendChild($catCat);
        $quiz->appendChild($cat);

        // ── Questions ──
        foreach ($questions as $q) {
            $q = (array)$q;
            $node = $dom->createElement('question');
            $node->setAttribute('type', $q['type'] ?? 'multichoice');

            // Name (truncated question text)
            $name = $dom->createElement('name');
            $nameText = $dom->createElement('text', htmlspecialchars(
                self::truncate($q['question_text'] ?? '', 50), ENT_XML1, 'UTF-8'
            ));
            $name->appendChild($nameText);
            $node->appendChild($name);

            // Question text
            $qt = $dom->createElement('questiontext');
            $qt->setAttribute('format', 'html');
            $qtText = $dom->createElement('text', htmlspecialchars(
                $q['question_text'] ?? '', ENT_XML1, 'UTF-8'
            ));
            $qt->appendChild($qtText);
            $node->appendChild($qt);

            // General feedback
            $gf = $dom->createElement('generalfeedback');
            $gf->setAttribute('format', 'html');
            $gfText = $dom->createElement('text', htmlspecialchars(
                ($q['feedback_correct'] ?? '') . "\n\n" . ($q['feedback_incorrect'] ?? ''),
                ENT_XML1, 'UTF-8'
            ));
            $gf->appendChild($gfText);
            $node->appendChild($gf);

            // Penalty (default 0.1 for all)
            $penalty = $dom->createElement('penalty', '0.1000000');
            $node->appendChild($penalty);

            // Hidden (always visible)
            $hidden = $dom->createElement('hidden', '0');
            $node->appendChild($hidden);

            // Idnumber (optional — leave empty)
            $idnumber = $dom->createElement('idnumber', '');
            $node->appendChild($idnumber);

            self::build_type_specific($dom, $node, $q);

            $quiz->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * Append type-specific XML elements to a question node.
     */
    private static function build_type_specific(\DOMDocument $dom, \DOMElement $node, array $q): void {
        $type = $q['type'] ?? 'multichoice';

        if ($type === 'multichoice') {
            $node->appendChild($dom->createElement('single', 'true'));
            $node->appendChild($dom->createElement('shuffleanswers', '1'));
            $node->appendChild($dom->createElement('answernumbering', 'abc'));

            $options = $q['options'] ?? [];
            $correctIdx = (int)($q['correct_answer_index'] ?? 0);
            foreach ($options as $i => $option) {
                $ans = $dom->createElement('answer');
                $ans->setAttribute('format', 'html');
                $ans->setAttribute('fraction', $i === $correctIdx ? '100' : '0');
                $ansText = $dom->createElement('text', htmlspecialchars($option, ENT_XML1, 'UTF-8'));
                $ans->appendChild($ansText);

                $fb = $dom->createElement('feedback');
                $fb->setAttribute('format', 'html');
                $fbText = $dom->createElement('text', htmlspecialchars(
                    $i === $correctIdx ? ($q['feedback_correct'] ?? '') : ($q['feedback_incorrect'] ?? ''),
                    ENT_XML1, 'UTF-8'
                ));
                $fb->appendChild($fbText);
                $ans->appendChild($fb);

                $node->appendChild($ans);
            }

        } elseif ($type === 'truefalse') {
            $node->appendChild($dom->createElement('answernumbering', 'none'));

            $correctIdx = (int)($q['correct_answer_index'] ?? 0);

            // True answer
            $trueAns = $dom->createElement('answer');
            $trueAns->setAttribute('format', 'html');
            $trueAns->setAttribute('fraction', $correctIdx === 0 ? '100' : '0');
            $trueText = $dom->createElement('text', 'true');
            $trueAns->appendChild($trueText);
            $trueFb = $dom->createElement('feedback');
            $trueFb->setAttribute('format', 'html');
            $trueFbText = $dom->createElement('text', htmlspecialchars(
                $correctIdx === 0 ? ($q['feedback_correct'] ?? '') : ($q['feedback_incorrect'] ?? ''),
                ENT_XML1, 'UTF-8'
            ));
            $trueFb->appendChild($trueFbText);
            $trueAns->appendChild($trueFb);
            $node->appendChild($trueAns);

            // False answer
            $falseAns = $dom->createElement('answer');
            $falseAns->setAttribute('format', 'html');
            $falseAns->setAttribute('fraction', $correctIdx === 1 ? '100' : '0');
            $falseText = $dom->createElement('text', 'false');
            $falseAns->appendChild($falseText);
            $falseFb = $dom->createElement('feedback');
            $falseFb->setAttribute('format', 'html');
            $falseFbText = $dom->createElement('text', htmlspecialchars(
                $correctIdx === 1 ? ($q['feedback_correct'] ?? '') : ($q['feedback_incorrect'] ?? ''),
                ENT_XML1, 'UTF-8'
            ));
            $falseFb->appendChild($falseFbText);
            $falseAns->appendChild($falseFb);
            $node->appendChild($falseAns);

        } elseif ($type === 'shortanswer') {
            $ans = $dom->createElement('answer');
            $ans->setAttribute('format', 'html');
            $ans->setAttribute('fraction', '100');
            $ansText = $dom->createElement('text', htmlspecialchars(
                $q['correct_text'] ?? '', ENT_XML1, 'UTF-8'
            ));
            $ans->appendChild($ansText);
            $fb = $dom->createElement('feedback');
            $fb->setAttribute('format', 'html');
            $fbText = $dom->createElement('text', htmlspecialchars(
                $q['feedback_correct'] ?? '', ENT_XML1, 'UTF-8'
            ));
            $fb->appendChild($fbText);
            $ans->appendChild($fb);
            $node->appendChild($ans);
        }
    }

    private static function truncate(string $text, int $length): string {
        if (mb_strlen($text) > $length) {
            return mb_substr($text, 0, $length) . '...';
        }
        return $text;
    }
}
