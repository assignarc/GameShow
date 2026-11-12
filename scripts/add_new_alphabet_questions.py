import os
import sqlite3
import json

db_path = '/Users/vishalkhapre/Documents/Code/GameShow/var/gameshow.db'
svg_dir = '/Users/vishalkhapre/Documents/Code/GameShow/public/uploads/puzzles/svg'
os.makedirs(svg_dir, exist_ok=True)

new_items = [
    {
        'letter': 'I',
        'title': 'Organ in Alphabet',
        'prompt': 'Organ in Alphabet',
        'hint': 'Eye',
        'answer': 'I',
        'alt_answers': ['Eye', 'I (Eye)', 'Letter I'],
        'explanation': 'Organ in Alphabet: Eye sounds identical to the letter I',
        'file': 'homophone_letter_i.svg',
        'accent': '#06b6d4',
        'bg_mid': '#164e63',
        'glow': '#22d3ee'
    },
    {
        'letter': 'O',
        'title': 'Surprise in Alphabet',
        'prompt': 'Surprise in Alphabet',
        'hint': 'Oh!',
        'answer': 'O',
        'alt_answers': ['Oh', 'O (Oh)', 'Oh!', 'Letter O'],
        'explanation': 'Surprise in Alphabet: The exclamation Oh! sounds identical to the letter O',
        'file': 'homophone_letter_o.svg',
        'accent': '#f59e0b',
        'bg_mid': '#78350f',
        'glow': '#fbbf24'
    },
    {
        'letter': 'X',
        'title': 'Math in Alphabet',
        'prompt': 'Math in Alphabet',
        'hint': 'Multiplication / times',
        'answer': 'X',
        'alt_answers': ['Multiply', 'Multiplication', 'Times', 'X (Times)', 'Letter X'],
        'explanation': 'Math in Alphabet: The multiplication symbol (times / variable x) is represented by the letter X',
        'file': 'homophone_letter_x.svg',
        'accent': '#8b5cf6',
        'bg_mid': '#4c1d95',
        'glow': '#a78bfa'
    }
]

# 1. Connect to SQLite DB
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

cat_id = cursor.execute("SELECT id FROM categories WHERE slug = 'alphabet-wordplay'").fetchone()[0]

for item in new_items:
    # Check if question already exists
    existing = cursor.execute("SELECT id FROM questions WHERE title = ? OR prompt_text = ?", (item['title'], item['prompt'])).fetchone()
    
    media_path = f"/uploads/puzzles/svg/{item['file']}"
    
    if existing:
        q_id = existing[0]
        cursor.execute("""
            UPDATE questions 
            SET category_id = ?, title = ?, prompt_text = ?, hint = ?, answer = ?, alternate_answers = ?, 
                explanation = ?, difficulty = 'Simple', hilarious_score = 9.0, media_type = 'image', 
                media_path = ?, original_media_path = ?, is_active = 1, is_favorite = 1, review_status = 'included'
            WHERE id = ?
        """, (
            cat_id,
            item['title'],
            item['prompt'],
            item['hint'],
            item['answer'],
            json.dumps(item['alt_answers']),
            item['explanation'],
            media_path,
            media_path,
            q_id
        ))
        print(f"Updated existing question {q_id}: {item['prompt']} = {item['answer']}")
    else:
        cursor.execute("""
            INSERT INTO questions (
                category_id, title, prompt_text, hint, answer, alternate_answers,
                explanation, difficulty, hilarious_score, media_type, media_path,
                original_media_path, media_config, source, is_active, is_favorite,
                review_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Simple', 9.0, 'image', ?, ?, '{}', 'User Alphabet Wordplay Series', 1, 1, 'included', CURRENT_TIMESTAMP)
        """, (
            cat_id,
            item['title'],
            item['prompt'],
            item['hint'],
            item['answer'],
            json.dumps(item['alt_answers']),
            item['explanation'],
            media_path,
            media_path
        ))
        q_id = cursor.lastrowid
        print(f"Inserted new question {q_id}: {item['prompt']} = {item['answer']}")

conn.commit()
conn.close()

# 2. Generate clean, spoiler-free SVGs
for item in new_items:
    svg_path = os.path.join(svg_dir, item['file'])
    
    svg_content = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700 440" width="100%" height="100%">
  <defs>
    <linearGradient id="bg_{item['letter']}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#080c14" />
      <stop offset="45%" stop-color="{item['bg_mid']}" />
      <stop offset="100%" stop-color="#040609" />
    </linearGradient>
    <radialGradient id="glow_{item['letter']}" cx="50%" cy="50%" r="55%">
      <stop offset="0%" stop-color="{item['glow']}" stop-opacity="0.30" />
      <stop offset="100%" stop-color="{item['glow']}" stop-opacity="0" />
    </radialGradient>
    <filter id="shadow_{item['letter']}" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="12" stdDeviation="16" flood-color="{item['accent']}" flood-opacity="0.5" />
    </filter>
    <filter id="card_shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="6" stdDeviation="12" flood-color="#000000" flood-opacity="0.6" />
    </filter>
  </defs>

  <!-- Outer Card Frame -->
  <rect width="700" height="440" rx="28" fill="url(#bg_{item['letter']})" stroke="#334155" stroke-width="2.5"/>
  <circle cx="350" cy="220" r="200" fill="url(#glow_{item['letter']})" />
  <rect x="22" y="22" width="656" height="396" rx="22" fill="none" stroke="{item['accent']}" stroke-width="1.5" stroke-dasharray="10,8" opacity="0.4"/>

  <!-- Top Badge: Pure Category Header -->
  <g transform="translate(350, 60)">
    <rect x="-105" y="-18" width="210" height="36" rx="18" fill="rgba(255,255,255,0.07)" stroke="{item['accent']}" stroke-width="1.5" filter="url(#card_shadow)"/>
    <text x="0" y="5" font-family="system-ui, -apple-system, sans-serif" font-size="13" font-weight="800" fill="{item['glow']}" text-anchor="middle" letter-spacing="2.5">REBUS ARENA</text>
  </g>

  <!-- Center Clue Board: Pure Clue Only -->
  <g transform="translate(350, 215)">
    <!-- Clue Banner -->
    <rect x="-295" y="-85" width="590" height="170" rx="24" fill="rgba(10, 14, 24, 0.88)" stroke="{item['accent']}" stroke-width="2" filter="url(#card_shadow)"/>
    
    <!-- Subtitle Category Tag -->
    <text x="0" y="-38" font-family="system-ui, -apple-system, sans-serif" font-size="12" font-weight="800" fill="{item['glow']}" text-anchor="middle" letter-spacing="3" opacity="0.8">WORDPLAY PUZZLE</text>
    
    <!-- Big Bold Clue -->
    <text x="0" y="22" font-family="system-ui, -apple-system, 'SF Pro Display', sans-serif" font-size="40" font-weight="900" fill="#ffffff" text-anchor="middle" letter-spacing="0.5">{item['prompt']}</text>
  </g>

  <!-- Bottom Mystery Box Tile -->
  <g transform="translate(350, 355)">
    <rect x="-38" y="-28" width="76" height="56" rx="14" fill="rgba(255,255,255,0.06)" stroke="{item['glow']}" stroke-width="2" stroke-dasharray="6,4"/>
    <text x="0" y="11" font-family="'Bungee', system-ui, sans-serif" font-size="34" font-weight="900" fill="{item['glow']}" text-anchor="middle">?</text>
  </g>
</svg>
'''
    with open(svg_path, 'w', encoding='utf-8') as f:
        f.write(svg_content.strip())
    print(f"Wrote clean clue card SVG: {svg_path}")

print("Done! All 3 new alphabet wordplay questions added and cards created.")

