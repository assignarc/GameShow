import glob, os, cv2, shutil, subprocess, sqlite3, numpy as np, re

vids = sorted([f for f in glob.glob('/Users/vishalkhapre/Downloads/quizes/*.mp4') if os.path.isfile(f)])
print(f'Starting extraction & ingestion for {len(vids)} videos...')

os.makedirs('public/uploads/puzzles/sparkz', exist_ok=True)
os.makedirs('/Users/vishalkhapre/Downloads/quizes/archive', exist_ok=True)

db_path = 'var/gameshow.db'
conn = sqlite3.connect(db_path)
cur = conn.cursor()

def clean_ocr(text):
    text = re.sub(r'[\x00-\x1f\x7f-\x9f]', '', text)
    lines = [l.strip() for l in text.split('\n') if l.strip()]
    return ' '.join(lines)

def solve_rebus(ocr_text, vid_name):
    clean = clean_ocr(ocr_text).strip()
    upper = clean.upper()
    
    # Common known patterns
    if 'SIGHT' in upper:
        return 'Insight', 'Sight enclosed between borders', 'The word SIGHT is literally placed inside borders, representing In Sight / Insight.'
    if 'BLAME' in upper:
        return 'No one to blame', '0. BLAME 2. BLAME', 'Wordplay on 0 (No), 1 (One), 2 (To) and Blame: No one to blame.'
    if 'INSULT' in upper or 'INJURY' in upper:
        return 'Add insult to injury', 'Injury + Insult equation', 'Mathematical sum of Injury + Insult representing the idiom Add insult to injury.'
    if 'PRICE' in upper and 'PAY' in upper:
        return 'A price to pay', 'PRICE PAY juxtaposed', 'The phrase price followed by pay creates A price to pay.'
    if 'STITCH' in upper:
        return 'A stitch in time', 'STITCH in brackets', 'Classic proverb represented visually: A stitch in time saves nine.'
    if 'CAMPING' in upper and 'NIGHT' in upper:
        return 'Overnight camping', 'Camping placed over Night', 'The word Camping is placed directly over the word Night, meaning Overnight camping.'
    if 'MILLAION' in upper or ('MILL' in upper and 'ION' in upper):
        return 'One in a million', 'The letter A inside MILLION', 'The letter A (one) inside the word MILLION represents One in a million.'
    if any(k in upper for k in ['1, 3, 5, 7, 9', '1,3,5,7,9', '1 3 5 7 9']):
        return 'Against all odds', 'Sequence of odd numbers 1, 3, 5, 7, 9', 'The odd numbers 1, 3, 5, 7, 9 represent the phrase Against all odds.'
    if 'SLEPT' in upper:
        return 'Overslept', 'SLEPT placed high on the card', 'The word SLEPT placed up high represents Overslept.'
    if 'LOST' in upper:
        return 'Lost and found', 'Repeated LOST words', 'Visual rebus on lost items: Lost and found.'
    if 'REPEAT' in upper and 'ME' in upper:
        return 'Repeat after me', 'REPEAT placed after ME', 'The word REPEAT appears right after the word ME, spelling Repeat after me.'
    if 'CONVERSATIO' in upper:
        return 'Interrupted conversation', 'CONVERSATION missing the final N', 'The word CONVERSATION is cut off before the end, representing an Interrupted conversation.'
    if any(deg in upper for deg in ['MSC', 'MPHIL', 'BA']):
        return 'Third degree', 'Academic degrees BA, MSc, MPhil', 'Three levels of academic degrees representing the phrase The Third Degree.'
    if 'JUST' in upper and 'ME' in upper:
        return 'Just between you and me', 'JUST and ME wordplay', 'Catchphrase riddle: Just between you and me.'
    if 'STAND' in upper:
        return 'I understand', 'I placed under STAND', 'The letter I placed under the word STAND represents I understand.'
    if 'READING' in upper or 'LINES' in upper:
        return 'Read between the lines', 'Words placed between lines', 'Words positioned between horizontal lines: Read between the lines.'
    if 'BREAK' in upper or 'FAST' in upper:
        return 'Breakfast', 'Fast with a break in the middle', 'Wordplay on Break and Fast: Breakfast.'
    
    # Generic fallback based on OCR words
    words = re.findall(r'[A-Za-z0-9]+', clean)
    if words:
        cand = ' '.join(words[:4]).title()
        return cand, f'Visual card showing: {clean}', f'Solve what this visual rebus card is displaying: {clean}'
    
    # Fallback to video title hint
    clean_v = re.sub(r'[#@_].*', '', vid_name).strip()
    return f'Rebus Riddle', f'Visual rebus from {clean_v}', 'Say what you see: inspect the visual card to decipher the hidden phrase.'

total_saved = 0
for idx, vpath in enumerate(vids):
    vname = os.path.basename(vpath)
    cap = cv2.VideoCapture(vpath)
    fps = cap.get(cv2.CAP_PROP_FPS) or 25.0
    total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    duration = total / fps
    
    candidates = []
    for sec in np.arange(1.5, duration - 1.5, 1.5):
        cap.set(cv2.CAP_PROP_POS_MSEC, int(sec * 1000))
        ret, frame = cap.read()
        if not ret or frame is None:
            continue
        h, w, _ = frame.shape
        if h < 800:
            continue
        card = frame[int(h*0.625):int(h*0.855), int(w*0.09):int(w*0.91)]
        m_b, m_g, m_r = card.mean(axis=(0,1))
        if m_r > 150 and m_g > 120 and (m_r - m_b) > 10:
            gray = cv2.cvtColor(card, cv2.COLOR_BGR2GRAY)
            sharpness = cv2.Laplacian(gray, cv2.CV_64F).var()
            candidates.append((sec, card, gray, sharpness))
            
    clusters = []
    for sec, card, gray, sharpness in candidates:
        small = cv2.resize(gray, (80, 50))
        matched = False
        for cl in clusters:
            diff = np.mean(np.abs(cl['small'].astype(float) - small.astype(float)))
            if diff < 22.0:
                matched = True
                if sharpness > cl['sharpness']:
                    cl['best_card'] = card
                    cl['sharpness'] = sharpness
                    cl['best_sec'] = sec
                cl['count'] += 1
                break
        if not matched:
            clusters.append({
                'small': small,
                'best_card': card,
                'sharpness': sharpness,
                'best_sec': sec,
                'count': 1
            })
            
    valid_puzzles = [c for c in clusters if c['count'] >= 2]
    if not valid_puzzles and clusters:
        valid_puzzles = [max(clusters, key=lambda x: x['count'])]
        
    for p_idx, p in enumerate(valid_puzzles, 1):
        filename = f'sparkz_v{idx:02d}_p{p_idx:02d}.jpg'
        rel_path = f'/uploads/puzzles/sparkz/{filename}'
        abs_path = os.path.abspath(f'public/uploads/puzzles/sparkz/{filename}')
        cv2.imwrite(abs_path, p['best_card'])
        
        # OCR
        res = subprocess.run(['/usr/local/bin/tesseract', abs_path, 'stdout', '--psm', '6'], capture_output=True)
        ocr_out = res.stdout.decode('utf-8', errors='ignore')
        
        ans, prompt, explanation = solve_rebus(ocr_out, vname)
        title = f'Rebus: {ans}'
        hint = f'Look closely at the letters, positions, and formatting on the card.'
        
        cur.execute('''
            INSERT INTO questions (
                category_id, title, prompt_text, hint, answer, explanation,
                difficulty, media_type, media_path, source, review_status, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            14, title, prompt, hint, ans, explanation,
            'Medium', 'image', rel_path, f'Instagram @sp_arkz_ (Reel: {vname[:30]})', 'unrated', 1
        ))
        total_saved += 1
        
    conn.commit()
    
    # Move video to archive
    dest = os.path.join('/Users/vishalkhapre/Downloads/quizes/archive', vname)
    shutil.move(vpath, dest)
    print(f'Done v_{idx:02d}: Saved {len(valid_puzzles)} puzzles and moved to archive.')

conn.close()
print(f'Successfully processed all {len(vids)} videos! Total new puzzles ingested: {total_saved}')

