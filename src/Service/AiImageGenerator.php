<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

class AiImageGenerator
{
    private string $projectDir;
    private string $uploadDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->uploadDir = $this->projectDir . '/public/uploads/ai';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    /**
     * Analyze a question and detect the rebus puzzle pattern and visual gag
     */
    public function analyzeRebusMechanic(array $question): array
    {
        $answer = trim($question['answer'] ?? '');
        $hint = trim($question['hint'] ?? '');
        $explanation = trim($question['explanation'] ?? '');
        $ansUpper = strtoupper($answer);

        // Pattern 1: Scrambled / Anagram / Mixed
        if (preg_match('/scramble|mix|jumble|whirl/i', $answer . ' ' . $hint . ' ' . $explanation)) {
            $words = preg_split('/\s+/', $answer);
            $targetWord = strtoupper(end($words) ?: $answer);
            if (strlen($targetWord) < 3 && count($words) > 1) {
                $targetWord = strtoupper($words[0]);
            }
            return [
                'type' => 'scrambled',
                'target' => $targetWord,
                'concept' => "The letters of '{$targetWord}' are scrambled and jumbled at random angles inside a puzzle ring/pan.",
                'diffusion_gag' => "A cartoon visual rebus puzzle: The letters '{$targetWord}' are scrambled and tumbling at crazy tilted angles inside a cartoon frying pan. Catchphrase TV game show card, Say What You See visual wordplay riddle, not real food, only scrambled alphabet letters.",
            ];
        }

        // Pattern 2: Green with envy / Out of the blue / Color wordplay
        if (preg_match('/green\s+with\s+envy/i', $answer)) {
            return [
                'type' => 'color',
                'word' => 'ENVY',
                'color' => '#10b981',
                'concept' => "The word 'ENVY' in huge bold 3D glowing neon emerald-green letters.",
                'diffusion_gag' => "TV Game Show Rebus Puzzle: The word 'ENVY' in giant bold 3D capital letters colored bright neon slime green on a dark game show puzzle board, visual wordplay riddle.",
            ];
        }
        if (preg_match('/out\s+of\s+the\s+blue/i', $answer)) {
            return [
                'type' => 'color',
                'word' => 'OUT',
                'color' => '#38bdf8',
                'concept' => "The word 'OUT' bursting out of a deep blue geometric portal.",
                'diffusion_gag' => "Rebus puzzle game card: The bold white letters 'OUT' bursting forward out of a deep blue square, visual idiom rebus.",
            ];
        }

        // Pattern 3: One in a million / Inside
        if (preg_match('/one\s+in\s+a\s+million/i', $answer)) {
            return [
                'type' => 'inside',
                'outer' => '1,000,000',
                'inner' => 'ONE',
                'concept' => "The number 1,000,000 with the word 'ONE' highlighted inside the zeroes.",
                'diffusion_gag' => "Catchphrase TV game show rebus card: A huge golden number 1,000,000 on a dark stage, with the letters 'O-N-E' glowing brightly inside the first zero. Visual riddle, Say What You See.",
            ];
        }

        // Pattern 4: Happy hour / Clock visual pun
        if (preg_match('/happy\s+hour/i', $answer)) {
            return [
                'type' => 'clock',
                'concept' => "A game-show wall clock at 5:00 with a giant happy cartoon smiling mouth and wink.",
                'diffusion_gag' => "Rebus visual pun for a TV game show: A large round cartoon clock with hands pointing at 5:00, with a huge happy smiling cartoon face grinning from the center of the clock dial. Catchphrase game show card, visual pun riddle.",
            ];
        }

        // Pattern 5: Eiffel Tower / Phonetic Icon Rebus
        if (preg_match('/eiffel\s+tower/i', $answer)) {
            return [
                'type' => 'phonetic',
                'icons' => ['👁️ Eye', '🍂 Fall', '🗼 Tower'],
                'concept' => "Phonetic rebus equation: An Eye + Falling Autumn Leaf + Tower = Eiffel Tower.",
                'diffusion_gag' => "TV Game Show Rebus Puzzle card: Three cartoon rebus clues lined up horizontally: A cartoon human eye, followed by an autumn leaf falling (Fall), followed by a tall radio antenna tower. Eye-Fall-Tower phonetic visual riddle.",
            ];
        }

        // Pattern 6: Split level / Split pea soup
        if (preg_match('/split\s+level/i', $answer)) {
            return [
                'type' => 'split',
                'left' => 'LE',
                'right' => 'VEL',
                'concept' => "The word 'LEVEL' split in two with a jagged lightning fissure between 'LE' and 'VEL'.",
                'diffusion_gag' => "Rebus puzzle graphic: The word 'LEVEL' split cleanly in half with a gap and jagged crack separating 'LE' and 'VEL', visual dingbat riddle.",
            ];
        }
        if (preg_match('/split/i', $answer)) {
            $words = explode(' ', $answer);
            $mid = $words[1] ?? $words[0];
            return [
                'type' => 'split',
                'left' => substr($mid, 0, (int) ceil(strlen($mid) / 2)),
                'right' => substr($mid, (int) ceil(strlen($mid) / 2)),
                'concept' => "The word '{$mid}' visually split in half with a dividing slash.",
                'diffusion_gag' => "Rebus puzzle card: The word '{$mid}' broken and split in half with a fissure, visual word riddle.",
            ];
        }

        // Pattern 7: Over / Under / Above / Below (Spatial Dingbat)
        if (preg_match('/(.*?)\s+(over|under|above|below|on)\s+(.*)/i', $answer, $m)) {
            $topWord = strtoupper(trim($m[1]));
            $prep = strtolower(trim($m[2]));
            $bottomWord = strtoupper(trim($m[3]));

            if ($prep === 'over' || $prep === 'above' || $prep === 'on') {
                return [
                    'type' => 'stacked',
                    'top' => $topWord,
                    'bottom' => $bottomWord,
                    'prep' => $prep,
                    'concept' => "'{$topWord}' placed directly above a divider line, over '{$bottomWord}'.",
                    'diffusion_gag' => "Dingbat rebus puzzle card: Bold text '{$topWord}' floating directly over the word '{$bottomWord}' with a bright horizontal line dividing them on a dark game show board, Say What You See visual riddle.",
                ];
            } else {
                return [
                    'type' => 'stacked',
                    'top' => $bottomWord,
                    'bottom' => $topWord,
                    'prep' => $prep,
                    'concept' => "'{$topWord}' placed underneath '{$bottomWord}'.",
                    'diffusion_gag' => "Dingbat rebus puzzle card: Bold text '{$topWord}' positioned underneath the word '{$bottomWord}', spatial wordplay puzzle.",
                ];
            }
        }

        // Pattern 8: Crossroads / Intersecting
        if (preg_match('/crossroad/i', $answer)) {
            return [
                'type' => 'crossed',
                'word' => 'ROAD',
                'concept' => "Two words 'ROAD' crossing each other at right angles in a plus (+) formation.",
                'diffusion_gag' => "TV Game show rebus puzzle card: Two street signs each saying 'ROAD' crossing perpendicular to each other like an X or plus sign, visual rebus riddle.",
            ];
        }

        // Specific Famous Idioms & Rebus Puzzles
        $ansLower = strtolower($answer);
        $specificPuzzles = [
            'illegal' => [
                'type' => 'phonetic',
                'concept' => "A cartoon sick eagle shivering in bed with a thermometer in its beak (Ill Eagle = Illegal).",
                'diffusion_gag' => "TV Game Show Catchphrase Rebus Card: A cartoon sick eagle wrapped in a warm blanket in bed with a thermometer in its beak, looking unwell (Ill Eagle = Illegal). Say What You See visual pun riddle, cartoon graphic art on dark game show background.",
            ],
            'apple of my eye' => [
                'type' => 'phonetic',
                'concept' => "A giant friendly cartoon eyeball with a glossy red apple replacing the pupil in the center.",
                'diffusion_gag' => "TV Game Show Catchphrase Rebus Card: A large cartoon eyeball with a shiny red apple sitting directly in place of the pupil and iris (Apple of my eye). Say What You See visual pun riddle, bold clean cartoon art on dark studio stage.",
            ],
            'sweet tooth' => [
                'type' => 'phonetic',
                'concept' => "A giant cartoon white molar tooth smiling and holding colorful lollipops and candy canes.",
                'diffusion_gag' => "TV Game Show Catchphrase Rebus Card: A large smiling cartoon white tooth with arms holding lollipops and candy canes, surrounded by wrapped sweets (Sweet Tooth). Say What You See visual wordplay, cartoon game show art on dark stage.",
            ],
            'launder money' => [
                'type' => 'phonetic',
                'concept' => "Paper dollar bills hanging on a clothesline with wooden clothespins and soap bubbles.",
                'diffusion_gag' => "TV Game Show Catchphrase Rebus Card: Paper green dollar bills pinned to a clothesline with wooden clothespins, with laundry soap suds and bubbles (Launder Money). Say What You See visual pun riddle, bold cartoon graphic art on dark stage.",
            ],
            'forehead' => [
                'type' => 'repeat',
                'word' => 'HEAD',
                'count' => 4,
                'concept' => "The word 'HEAD' repeated four times in a column: HEAD, HEAD, HEAD, HEAD (Four Heads = Forehead).",
                'diffusion_gag' => "Dingbat rebus puzzle card: The word 'HEAD' printed four times in a neat vertical column (HEAD, HEAD, HEAD, HEAD = Forehead). Spatial typography wordplay on a dark game show card.",
            ],
            'street corner' => [
                'type' => 'general_rebus',
                'concept' => "The word 'STREET' bent at a 90-degree right angle forming a street corner.",
                'diffusion_gag' => "Dingbat rebus puzzle card: The word 'STREET' sharply bent at a 90-degree right angle to form a street corner. Say What You See spatial typography wordplay on dark game show card.",
            ],
            'touchdown' => [
                'type' => 'general_rebus',
                'concept' => "The word 'TOUCH' printed at the bottom of the card with an arrow pointing downward.",
                'diffusion_gag' => "Dingbat rebus card: The word 'TOUCH' positioned at the very bottom edge of the card with a bright orange arrow pointing downward (Touchdown). Spatial typography puzzle on dark game show card.",
            ],
            'bookcase' => [
                'type' => 'inside',
                'outer' => 'C   ASE',
                'inner' => 'BOOK',
                'concept' => "The word 'BOOK' enclosed inside the word 'CASE' (C [BOOK] ASE).",
                'diffusion_gag' => "Dingbat rebus puzzle card: The word 'BOOK' placed inside the letters of 'CASE' (C [BOOK] ASE). Spatial typography game show puzzle card on dark background.",
            ],
            'sweatbox' => [
                'type' => 'general_rebus',
                'concept' => "A wooden crate box labeled 'SWEAT' with cartoon sweat droplets spraying out of it.",
                'diffusion_gag' => "Catchphrase TV game show rebus card: A cartoon wooden box labeled 'SWEAT' with giant cartoon water sweat drops spraying outwards. Say What You See visual pun riddle on dark studio background.",
            ],
            'man overboard' => [
                'type' => 'stacked',
                'top' => 'MAN',
                'bottom' => 'BOARD',
                'prep' => 'over',
                'concept' => "The word 'MAN' floating directly above a divider bar, and the word 'BOARD' beneath it.",
                'diffusion_gag' => "Dingbat rebus puzzle card: The bold word 'MAN' positioned directly above a horizontal golden line, with the word 'BOARD' beneath it (Man Overboard). Say What You See spatial wordplay on dark game show card.",
            ],
            'long time, no see' => [
                'type' => 'general_rebus',
                'concept' => "The word 'TIME' stretched out horizontally very long, and letters 'C C C C' crossed out with red X's.",
                'diffusion_gag' => "Catchphrase TV game show rebus card: The word 'TIME' stretched out horizontally across the whole card, next to four letters 'C C C C' crossed out with red X marks (no C's = no see). Visual word riddle on dark stage.",
            ],
            'you are in trouble' => [
                'type' => 'inside',
                'outer' => 'TROUBLE',
                'inner' => 'U R',
                'concept' => "The letters 'U R' positioned inside the word 'TROUBLE' (T-R-O [U R] B-L-E).",
                'diffusion_gag' => "Dingbat rebus puzzle card: The letters 'U R' highlighted in glowing amber directly inside the word 'TROUBLE' (T-R-O [U R] B-L-E). Spatial typography wordplay on dark game show board.",
            ],
            'split pea soup' => [
                'type' => 'split',
                'left' => 'PE',
                'right' => 'A',
                'concept' => "A soup bowl with letters 'S O U P', and in the middle a green pea cleanly split in half.",
                'diffusion_gag' => "Catchphrase TV game show rebus card: A cartoon soup bowl with the letters 'S O U P', and in the center a green pea split in half with a dotted line (Split pea soup). Visual word riddle, cartoon graphic art.",
            ],
            'travel overseas' => [
                'type' => 'stacked',
                'top' => 'TRAVEL',
                'bottom' => 'C C C C C',
                'prep' => 'over',
                'concept' => "The word 'TRAVEL' floating above a row of blue wavy letters 'C C C C C' (seas).",
                'diffusion_gag' => "Dingbat rebus puzzle card: The bold word 'TRAVEL' positioned directly above a row of blue wavy letters 'C C C C C' (Travel Overseas). Spatial typography game show riddle on dark card.",
            ],
            'fortunate' => [
                'type' => 'general_rebus',
                'concept' => "The numbers '4', '10', '8' written in huge bold digits in a row (Four Ten Eight = Fortunate).",
                'diffusion_gag' => "Dingbat rebus puzzle card: The numbers '4', '10', '8' in large bold glowing font side-by-side (Four Ten Eight = Fortunate). Say What You See puzzle card.",
            ],
            'checkpoint' => [
                'type' => 'phonetic',
                'concept' => "A large green checkmark (Check) next to a cartoon hand with a pointing finger (Point).",
                'diffusion_gag' => "Catchphrase TV game show card: A large green checkmark (Check) next to a cartoon hand pointing its index finger (Point = Checkpoint). Say What You See visual riddle on dark stage.",
            ],
            'dirty joke' => [
                'type' => 'general_rebus',
                'concept' => "The word 'JOKE' splattered with cartoon brown mud and dirt spots.",
                'diffusion_gag' => "Catchphrase TV game show card: The word 'JOKE' in bold 3D letters covered in cartoon brown mud splatters and dirt puddles (Dirty Joke). Visual wordplay riddle on dark background.",
            ],
        ];

        foreach ($specificPuzzles as $pattern => $puzzleData) {
            if (str_contains($ansLower, $pattern)) {
                return $puzzleData;
            }
        }

        // Pattern 9: Repetition / Multipliers (Tricycle, Double vision, Side by side)
        if (preg_match('/(tricycle|tri|three)/i', $answer)) {
            return [
                'type' => 'repeat',
                'word' => 'CYCLE',
                'count' => 3,
                'concept' => "The word 'CYCLE' repeated three times: 'CYCLE  CYCLE  CYCLE'.",
                'diffusion_gag' => "Dingbat rebus puzzle: The word 'CYCLE' written three times side-by-side in bold white block letters on a game show card, visual riddle.",
            ];
        }
        if (preg_match('/(double|two|twice|pair)/i', $answer) || preg_match('/side\s+by\s+side/i', $answer)) {
            $words = explode(' ', $answer);
            $w = strtoupper(end($words) ?: 'VISION');
            return [
                'type' => 'repeat',
                'word' => $w,
                'count' => 2,
                'concept' => "The word '{$w}' printed twice side-by-side: '{$w}   {$w}'.",
                'diffusion_gag' => "Dingbat puzzle card: The word '{$w}' displayed twice side by side in bold capital letters, visual riddle.",
            ];
        }

        // Default: Clean Game-Show Typographic Rebus Equation
        $words = preg_split('/\s+/', $answer);
        return [
            'type' => 'general_rebus',
            'words' => $words,
            'concept' => "Stylized Game-Show Dingbat Rebus for '{$answer}' with bold typography and graphical wordplay.",
            'diffusion_gag' => "TV Game Show Catchphrase Rebus Card: A visual riddle card on dark stage background. Say What You See puzzle game. Bold graphic puzzle art representing the visual wordplay for '{$answer}'. DO NOT show a realistic photograph, show an illustrated puzzle riddle card.",
        ];
    }

    /**
     * Builds the prompt for AI Diffusion models (Catchphrase TV Game Show Riddle)
     */
    public function buildDiffusionPrompt(array $question, string $style = 'rebus'): string
    {
        $mechanic = $this->analyzeRebusMechanic($question);
        $answer = trim($question['answer'] ?? '');
        $gag = $mechanic['diffusion_gag'];

        return match ($style) {
            'cartoon' => "{$gag} 1980s retro cartoon Catchphrase game show illustration, vibrant colors, clean comic line art, dark stage background.",
            '3d' => "{$gag} 3D clay-render isometric game show puzzle board with dramatic studio lighting and glowing neon edges.",
            'minimal' => "{$gag} Minimalist flat graphic design, high contrast amber and gold typography on dark slate background.",
            default => "{$gag} Bold graphic game-show puzzle card, Catchphrase TV show style, clean sharp artwork on dark studio backdrop.",
        };
    }

    /**
     * Builds the default concept description shown in the UI modal
     */
    public function buildDefaultPrompt(array $question, string $style = 'rebus'): string
    {
        $mechanic = $this->analyzeRebusMechanic($question);
        return $mechanic['concept'];
    }

    /**
     * Generates a Rebus Puzzle image:
     * - Vector SVG Rebus (Instant, authentic, pixel-perfect dingbat / rebus card)
     * - Or AI Diffusion (Catchphrase-style visual riddle via Pollinations in High Resolution)
     */
    public function generate(
        int $questionId, 
        array $question, 
        string $prompt, 
        string $style = 'rebus', 
        ?int $seed = null, 
        int $width = 1280, 
        int $height = 800
    ): array {
        $seed = $seed ?? random_int(10000, 999999);
        $mechanic = $this->analyzeRebusMechanic($question);

        // Ensure minimum HD dimensions if invalid values provided
        $width = max(800, min(2560, $width));
        $height = max(500, min(1600, $height));

        // If style is vector_rebus or dingbat, generate the SVG Rebus Puzzle
        if ($style === 'vector_rebus' || $style === 'dingbat' || $style === 'rebus_card') {
            $svgFilename = sprintf('rebus_%d_%d_%s.svg', $questionId, $seed, bin2hex(random_bytes(3)));
            $svgPath = $this->uploadDir . '/' . $svgFilename;
            $svgContent = $this->generateAuthenticRebusSvg($question, $mechanic, $seed, $width, $height);
            file_put_contents($svgPath, $svgContent);

            return [
                'success' => true,
                'url' => '/uploads/ai/' . $svgFilename,
                'prompt' => $mechanic['concept'],
                'seed' => $seed,
                'format' => 'svg',
                'dimensions' => "{$width}x{$height}",
                'type' => 'vector_rebus',
            ];
        }

        // Otherwise: Generate Catchphrase TV Game Show Visual Riddle via Diffusion AI in High Resolution
        $diffusionPrompt = $this->buildDiffusionPrompt($question, $style);
        // Include any user-customized prompt text if provided and different from default
        if (!empty($prompt) && $prompt !== $mechanic['concept']) {
            $diffusionPrompt = "TV Game Show Catchphrase Rebus Puzzle Card: Say What You See visual riddle. " . $prompt . ". Clean graphic art, game show puzzle board, dark background, no stray text.";
        }

        $encodedPrompt = rawurlencode($diffusionPrompt);
        $pollinationsUrl = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width={$width}&height={$height}&seed={$seed}&nologo=true";

        $imageBytes = $this->fetchUrlWithTimeout($pollinationsUrl, 16);
        if ($imageBytes !== null && strlen($imageBytes) > 2500) {
            $filename = sprintf('ai_rebus_%d_%d_%s.jpg', $questionId, $seed, bin2hex(random_bytes(3)));
            $filePath = $this->uploadDir . '/' . $filename;
            if (file_put_contents($filePath, $imageBytes) !== false) {
                return [
                    'success' => true,
                    'url' => '/uploads/ai/' . $filename,
                    'prompt' => $diffusionPrompt,
                    'seed' => $seed,
                    'format' => 'jpg',
                    'dimensions' => "{$width}x{$height}",
                    'type' => 'diffusion_rebus',
                ];
            }
        }

        // Fallback: Generate the authentic SVG Rebus Puzzle card
        $svgFilename = sprintf('rebus_%d_%d_%s.svg', $questionId, $seed, bin2hex(random_bytes(3)));
        $svgPath = $this->uploadDir . '/' . $svgFilename;
        $svgContent = $this->generateAuthenticRebusSvg($question, $mechanic, $seed, $width, $height);
        file_put_contents($svgPath, $svgContent);

        return [
            'success' => true,
            'url' => '/uploads/ai/' . $svgFilename,
            'prompt' => $mechanic['concept'],
            'seed' => $seed,
            'format' => 'svg',
            'dimensions' => "{$width}x{$height}",
            'type' => 'vector_rebus',
        ];
    }

    /**
     * Generates an authentic, broadcast-grade Rebus Puzzle SVG card
     */
    public function generateAuthenticRebusSvg(array $question, array $mechanic, int $seed, int $width = 1280, int $height = 800): string
    {
        $answer = strtoupper(trim($question['answer'] ?? 'REBUS'));
        $title = htmlspecialchars(trim($question['title'] ?? 'Puzzle Clue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $palettes = [
            ['bg1' => '#14110e', 'bg2' => '#241d18', 'accent' => '#f59e0b', 'text' => '#f8fafc', 'border' => '#524336'],
            ['bg1' => '#0f172a', 'bg2' => '#1e293b', 'accent' => '#38bdf8', 'text' => '#ffffff', 'border' => '#334155'],
            ['bg1' => '#18181b', 'bg2' => '#27272a', 'accent' => '#10b981', 'text' => '#ffffff', 'border' => '#3f3f46'],
            ['bg1' => '#1a0b1e', 'bg2' => '#2d1838', 'accent' => '#e879f9', 'text' => '#ffffff', 'border' => '#582b6b'],
        ];
        $p = $palettes[$seed % count($palettes)];
        $gradId = 'bg_' . $seed;

        $contentSvg = '';

        switch ($mechanic['type']) {
            case 'scrambled':
                // Scrambled letters rotated at dynamic angles inside a circular pan/board
                $word = $mechanic['target'];
                $letters = str_split($word);
                $contentSvg .= '<circle cx="400" cy="240" r="140" fill="none" stroke="' . $p['accent'] . '" stroke-width="4" stroke-dasharray="8,8" opacity="0.4"/>';
                $contentSvg .= '<circle cx="400" cy="240" r="130" fill="rgba(0,0,0,0.3)"/>';

                $count = count($letters);
                $radius = 70;
                foreach ($letters as $idx => $char) {
                    $angle = ($idx * (360 / max(1, $count))) + (($seed + $idx * 37) % 45) - 20;
                    $rad = deg2rad($angle);
                    $cx = 400 + (int) ($radius * cos($rad));
                    $cy = 240 + (int) ($radius * sin($rad));
                    $tilt = (($idx * 43 + $seed) % 60) - 30;

                    $contentSvg .= sprintf(
                        '<g transform="translate(%d, %d) rotate(%d)">
                            <rect x="-35" y="-35" width="70" height="70" rx="14" fill="#1c1815" stroke="%s" stroke-width="3" filter="drop-shadow(0 6px 12px rgba(0,0,0,0.6))"/>
                            <text x="0" y="12" font-family="system-ui, -apple-system, sans-serif" font-size="44" font-weight="900" fill="%s" text-anchor="middle">%s</text>
                        </g>',
                        $cx, $cy, $tilt, $p['accent'], $p['text'], $char
                    );
                }
                break;

            case 'stacked':
                // Top word, divider bar, Bottom word (e.g. MAN over BOARD)
                $top = htmlspecialchars($mechanic['top'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $bottom = htmlspecialchars($mechanic['bottom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $contentSvg .= sprintf(
                    '<text x="400" y="180" font-family="system-ui, -apple-system, sans-serif" font-size="52" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="3">%s</text>
                     <line x1="160" y1="230" x2="640" y2="230" stroke="%s" stroke-width="5" stroke-linecap="round"/>
                     <text x="400" y="305" font-family="system-ui, -apple-system, sans-serif" font-size="52" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="3">%s</text>',
                    $p['text'], $top, $p['accent'], $p['text'], $bottom
                );
                break;

            case 'repeat':
                // Repeated words (e.g. CYCLE CYCLE CYCLE)
                $word = htmlspecialchars($mechanic['word'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $count = (int) ($mechanic['count'] ?? 3);
                $repeatedStr = implode('   ', array_fill(0, $count, $word));
                $fontSize = $count >= 3 ? 38 : 48;
                $contentSvg .= sprintf(
                    '<text x="400" y="250" font-family="system-ui, -apple-system, sans-serif" font-size="%d" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="4">%s</text>',
                    $fontSize, $p['text'], $repeatedStr
                );
                break;

            case 'color':
                // Colored word (e.g. ENVY in green)
                $word = htmlspecialchars($mechanic['word'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $col = $mechanic['color'];
                $contentSvg .= sprintf(
                    '<text x="400" y="260" font-family="system-ui, -apple-system, sans-serif" font-size="84" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="8" filter="drop-shadow(0 0 25px %s)">%s</text>',
                    $col, $col, $word
                );
                break;

            case 'split':
                // Word split apart with jagged fissure line
                $left = htmlspecialchars($mechanic['left'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $right = htmlspecialchars($mechanic['right'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 245)">
                        <text x="-90" y="15" font-family="system-ui, -apple-system, sans-serif" font-size="64" font-weight="900" fill="%s" text-anchor="end" letter-spacing="4">%s</text>
                        <path d="M -15 -80 L 10 -30 L -10 10 L 15 50 L -5 90" stroke="%s" stroke-width="4" fill="none" stroke-linecap="round"/>
                        <text x="90" y="15" font-family="system-ui, -apple-system, sans-serif" font-size="64" font-weight="900" fill="%s" text-anchor="start" letter-spacing="4">%s</text>
                    </g>',
                    $p['text'], $left, $p['accent'], $p['text'], $right
                );
                break;

            case 'inside':
                // Word inside a number or outer frame (e.g. ONE in 1,000,000)
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 245)">
                        <text x="-160" y="20" font-family="system-ui, -apple-system, sans-serif" font-size="68" font-weight="900" fill="%s" text-anchor="middle">1,</text>
                        <rect x="-80" y="-55" width="160" height="90" rx="16" fill="#1c1815" stroke="%s" stroke-width="4" filter="drop-shadow(0 0 15px rgba(245,158,11,0.4))"/>
                        <text x="0" y="14" font-family="system-ui, -apple-system, sans-serif" font-size="44" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="3">%s</text>
                        <text x="170" y="20" font-family="system-ui, -apple-system, sans-serif" font-size="68" font-weight="900" fill="%s" text-anchor="middle">,000</text>
                    </g>',
                    $p['text'], $p['accent'], $p['accent'], $mechanic['inner'], $p['text']
                );
                break;

            case 'clock':
                // Clock with happy face
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 235)">
                        <circle r="110" fill="#1c1815" stroke="%s" stroke-width="6" filter="drop-shadow(0 10px 25px rgba(0,0,0,0.7))"/>
                        <!-- 12, 3, 6, 9 marks -->
                        <line x1="0" y1="-95" x2="0" y2="-80" stroke="%s" stroke-width="4"/>
                        <line x1="95" y1="0" x2="80" y2="0" stroke="%s" stroke-width="4"/>
                        <line x1="0" y1="95" x2="0" y2="80" stroke="%s" stroke-width="4"/>
                        <line x1="-95" y1="0" x2="-80" y2="0" stroke="%s" stroke-width="4"/>
                        <!-- Hands at 5:00 -->
                        <line x1="0" y1="0" x2="0" y2="-65" stroke="#ffffff" stroke-width="5" stroke-linecap="round"/>
                        <line x1="0" y1="0" x2="45" y2="45" stroke="%s" stroke-width="5" stroke-linecap="round"/>
                        <circle r="8" fill="%s"/>
                        <!-- Happy smiling face -->
                        <circle cx="-35" cy="-25" r="9" fill="%s"/>
                        <circle cx="35" cy="-25" r="9" fill="%s"/>
                        <path d="M -45 25 Q 0 80 45 25" fill="none" stroke="%s" stroke-width="7" stroke-linecap="round"/>
                    </g>',
                    $p['accent'], $p['accent'], $p['accent'], $p['accent'], $p['accent'],
                    $p['accent'], $p['accent'], $p['accent'], $p['accent'], $p['accent']
                );
                break;

            case 'phonetic':
                // Three icons side-by-side in equation format: [Icon 1] + [Icon 2] + [Icon 3]
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 235)">
                        <rect x="-290" y="-70" width="160" height="130" rx="14" fill="#1c1815" stroke="%s" stroke-width="3"/>
                        <text x="-210" y="10" font-size="52" text-anchor="middle">👁️</text>
                        <text x="-210" y="42" font-family="system-ui, sans-serif" font-size="14" font-weight="800" fill="%s" text-anchor="middle">EYE</text>

                        <text x="-105" y="15" font-family="system-ui, sans-serif" font-size="44" font-weight="900" fill="%s" text-anchor="middle">+</text>

                        <rect x="-80" y="-70" width="160" height="130" rx="14" fill="#1c1815" stroke="%s" stroke-width="3"/>
                        <text x="0" y="10" font-size="52" text-anchor="middle">🍂</text>
                        <text x="0" y="42" font-family="system-ui, sans-serif" font-size="14" font-weight="800" fill="%s" text-anchor="middle">FALL</text>

                        <text x="105" y="15" font-family="system-ui, sans-serif" font-size="44" font-weight="900" fill="%s" text-anchor="middle">+</text>

                        <rect x="130" y="-70" width="160" height="130" rx="14" fill="#1c1815" stroke="%s" stroke-width="3"/>
                        <text x="210" y="10" font-size="52" text-anchor="middle">🗼</text>
                        <text x="210" y="42" font-family="system-ui, sans-serif" font-size="14" font-weight="800" fill="%s" text-anchor="middle">TOWER</text>
                    </g>',
                    $p['border'], $p['accent'], $p['accent'], $p['border'], $p['accent'], $p['accent'], $p['border'], $p['accent']
                );
                break;

            case 'crossed':
                // Intersecting ROAD and ROAD
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 240)">
                        <!-- Horizontal ROAD -->
                        <text x="0" y="16" font-family="system-ui, sans-serif" font-size="56" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="8">R O A D</text>
                        <!-- Vertical ROAD -->
                        <text x="0" y="-85" font-family="system-ui, sans-serif" font-size="56" font-weight="900" fill="%s" text-anchor="middle">R</text>
                        <text x="0" y="85" font-family="system-ui, sans-serif" font-size="56" font-weight="900" fill="%s" text-anchor="middle">A</text>
                        <text x="0" y="145" font-family="system-ui, sans-serif" font-size="56" font-weight="900" fill="%s" text-anchor="middle">D</text>
                    </g>',
                    $p['accent'], $p['text'], $p['text'], $p['text']
                );
                break;

            default:
                // General Dingbat Rebus card with high-impact layout
                $display = htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $contentSvg .= sprintf(
                    '<g transform="translate(400, 245)">
                        <rect x="-260" y="-60" width="520" height="110" rx="16" fill="#1c1815" stroke="%s" stroke-width="3" filter="drop-shadow(0 10px 25px rgba(0,0,0,0.6))"/>
                        <text x="0" y="15" font-family="system-ui, -apple-system, sans-serif" font-size="44" font-weight="900" fill="%s" text-anchor="middle" letter-spacing="3">%s</text>
                    </g>',
                    $p['accent'], $p['text'], $display
                );
                break;
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="{$width}" height="{$height}">
  <defs>
    <linearGradient id="{$gradId}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$p['bg1']}" />
      <stop offset="100%" stop-color="{$p['bg2']}" />
    </linearGradient>
    <pattern id="grid_{$seed}" width="28" height="28" patternUnits="userSpaceOnUse">
      <circle cx="3" cy="3" r="1.5" fill="{$p['accent']}" opacity="0.12" />
    </pattern>
  </defs>

  <!-- Card Background -->
  <rect width="800" height="500" rx="20" fill="url(#{$gradId})"/>
  <rect width="800" height="500" rx="20" fill="url(#grid_{$seed})"/>
  
  <!-- Outer Game Show Golden Border -->
  <rect x="18" y="18" width="764" height="464" rx="16" fill="none" stroke="{$p['border']}" stroke-width="2.5" stroke-dasharray="8,8"/>
  <rect x="26" y="26" width="748" height="448" rx="12" fill="none" stroke="{$p['accent']}" stroke-width="1.5" opacity="0.3"/>

  <!-- Top Badge: SAY WHAT YOU SEE -->
  <g transform="translate(400, 65)">
    <rect x="-140" y="-18" width="280" height="34" rx="17" fill="{$p['accent']}" opacity="0.15"/>
    <rect x="-140" y="-18" width="280" height="34" rx="17" fill="none" stroke="{$p['accent']}" stroke-width="1.5"/>
    <text y="5" font-family="system-ui, -apple-system, sans-serif" font-size="12" font-weight="900" fill="{$p['accent']}" text-anchor="middle" letter-spacing="2">
      SAY WHAT YOU SEE • REBUS
    </text>
  </g>

  <!-- Main Puzzle Graphic Content -->
  {$contentSvg}

  <!-- Bottom Puzzle Footer -->
  <text x="400" y="445" font-family="system-ui, -apple-system, sans-serif" font-size="13" font-weight="700" fill="#64748b" text-anchor="middle" letter-spacing="1">
    FALL PARTY REBUS CHAMPIONSHIP
  </text>
</svg>
SVG;
    }

    private function fetchUrlWithTimeout(string $url, int $timeoutSeconds = 7): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT => 'GameShow-AI-ImageStudio/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode >= 200 && $httpCode < 300 && is_string($result)) {
                return $result;
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'header' => "User-Agent: GameShow-AI-ImageStudio/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        return $content !== false ? $content : null;
    }

    /**
     * Physically upscales an existing image file using GD super-sampling,
     * bicubic resampling, and unsharp edge sharpening.
     */
    public function upscaleImage(string $relativePath, int $targetMinWidth = 1280): array
    {
        $cleanRel = '/' . ltrim($relativePath, '/');
        $fullPath = $this->projectDir . '/public' . $cleanRel;

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return [
                'success' => false,
                'error' => 'Source image file not found on disk: ' . $relativePath,
            ];
        }

        $rawBytes = @file_get_contents($fullPath);
        if ($rawBytes === false || strlen($rawBytes) < 50) {
            return [
                'success' => false,
                'error' => 'Unable to read source image bytes',
            ];
        }

        // Handle vector SVGs: already crisp and infinitely scalable
        if (str_ends_with(strtolower($cleanRel), '.svg') || str_contains($rawBytes, '<svg')) {
            return [
                'success' => true,
                'url' => $cleanRel,
                'original_width' => 1280,
                'original_height' => 800,
                'width' => 1920,
                'height' => 1200,
                'dimensions' => 'Vector SVG (Infinite Scale)',
                'scale_factor' => 'Vector HD',
            ];
        }

        $src = @imagecreatefromstring($rawBytes);
        if ($src === false) {
            return [
                'success' => false,
                'error' => 'Unsupported image format or corrupted image file',
            ];
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW <= 0 || $origH <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid image dimensions',
            ];
        }

        // If image is already high resolution (>= 1280px wide), keep it sharp and bound to max 1920px
        if ($origW >= 1280) {
            $targetW = min(1920, $origW);
            $targetH = (int) round($origH * ($targetW / $origW));
            $scaleLabel = '1× High-Res';
        } else {
            // Target width between 1280px and 1920px (scale small graphics 2x to 4x)
            $multiplier = min(4, max(2, (int) ceil($targetMinWidth / $origW)));
            $targetW = min(1920, (int) ($origW * $multiplier));
            $targetH = (int) round($origH * ($targetW / $origW));
            $scaleLabel = $multiplier . '× HD';
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        // High quality bicubic resampling
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

        // Apply unsharp edge sharpening filter
        $sharpen = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        imageconvolution($dst, $sharpen, 8, 0);

        $upscaleDir = $this->projectDir . '/public/uploads/upscaled';
        if (!is_dir($upscaleDir)) {
            mkdir($upscaleDir, 0777, true);
        }

        $isPngOrGif = str_ends_with(strtolower($cleanRel), '.png') || str_ends_with(strtolower($cleanRel), '.gif');
        if ($isPngOrGif) {
            $filename = sprintf('up_%s_%s.png', bin2hex(random_bytes(6)), time());
            $outPath = $upscaleDir . '/' . $filename;
            imagepng($dst, $outPath, 1); // Fast compression
        } else {
            $filename = sprintf('up_%s_%s.jpg', bin2hex(random_bytes(6)), time());
            $outPath = $upscaleDir . '/' . $filename;
            imagejpeg($dst, $outPath, 92);
        }

        return [
            'success' => true,
            'url' => '/uploads/upscaled/' . $filename,
            'original_width' => $origW,
            'original_height' => $origH,
            'width' => $targetW,
            'height' => $targetH,
            'dimensions' => "{$targetW}x{$targetH}",
            'scale_factor' => $scaleLabel,
        ];
    }
}
