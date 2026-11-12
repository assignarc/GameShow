import cv2, sqlite3, glob, os

all_archived = sorted(glob.glob('/Users/vishalkhapre/Downloads/quizes/archive/*.mp4'))
older = ['AQMn', 'AQMtn', 'AQO2', 'What common word', 'What is this Puzzle sayingDay - 974#rebus', '🧩🧠 Think Fast']
new_28 = [f for f in all_archived if not any(os.path.basename(f).startswith(o) for o in older)]

# 1. Fix images where a sharper or different frame was needed:
# v_06: overwrite sparkz_v06_p02.jpg with frame at 90s (Light at the end of the tunnel)
cap6 = cv2.VideoCapture(new_28[6])
cap6.set(cv2.CAP_PROP_POS_MSEC, 90000)
ret, f6_90 = cap6.read()
if ret:
    cv2.imwrite('public/uploads/puzzles/sparkz/sparkz_v06_p02.jpg', f6_90[794:794+304, 53:53+619])
    print('Updated sparkz_v06_p02.jpg with sharp Light at the end of the tunnel frame')

# v_06: overwrite sparkz_v06_p03.jpg with audience frame at 147s (Walk in the park: PAWALKRK)
cap6.set(cv2.CAP_PROP_POS_MSEC, 147000)
ret, f6_147 = cap6.read()
if ret:
    cv2.imwrite('public/uploads/puzzles/sparkz/sparkz_v06_p03.jpg', f6_147[794:794+304, 53:53+619])
    print('Updated sparkz_v06_p03.jpg with Walk in the Park frame')

# v_11: overwrite sparkz_v11_p01.jpg with frame at 20s (History repeats itself)
cap11 = cv2.VideoCapture(new_28[11])
cap11.set(cv2.CAP_PROP_POS_MSEC, 20000)
ret, f11_20 = cap11.read()
if ret:
    cv2.imwrite('public/uploads/puzzles/sparkz/sparkz_v11_p01.jpg', f11_20[790:790+307, 71:71+584])
    print('Updated sparkz_v11_p01.jpg with sharp History repeats itself frame')

# 2. Database updates for all 13 items
conn = sqlite3.connect('var/gameshow.db')
cur = conn.cursor()

updates = {
    1605: (
        "Ocean's 11",
        "Rebus: Ocean's 11",
        "What movie title does this visual rebus represent?",
        "Count how many bodies of water (oceans) are shown.",
        'Visual rebus showing 11 oceans representing the heist movie "Ocean\'s 11".'
    ),
    1611: (
        "Light at the end of the tunnel",
        "Rebus: Light at the end of the tunnel",
        "What idiom does this visual rebus represent?",
        "A balance scale weighing TUNNEL against the letter L (light).",
        'A scale weighing the word TUNNEL heavier than L represents "Light at the end of the tunnel".'
    ),
    1615: (
        "Double or nothing",
        "Rebus: Double or nothing",
        "What betting or gaming phrase does this visual rebus represent?",
        "Look at the word OR and notice what is placed on the other side.",
        'The word OR shown with an empty (nothing) space on its other side represents "Double or nothing".'
    ),
    1623: (
        "World record",
        "Rebus: World record",
        "What achievement phrase does this visual rebus represent?",
        "A world map printed on a vinyl music recording disc.",
        'A world globe combined with a vinyl phonograph record disc represents a "World record".'
    ),
    1625: (
        "History repeats itself",
        "Rebus: History repeats itself",
        "What famous saying does this visual rebus represent?",
        "The word History appears multiple times in a row.",
        'The word HISTORY repeated consecutively represents "History repeats itself".'
    ),
    1636: (
        "Misunderstanding between friends",
        "Rebus: Misunderstanding between friends",
        "What phrase does this visual rebus represent?",
        "The word STANDING placed between FRI and ENDS (friends).",
        'The word STANDING placed under/between FRI and ENDS spells out "Misunderstanding between friends".'
    ),
    1640: (
        "Death sentence",
        "Rebus: Death sentence",
        "What legal term or idiom does this visual rebus represent?",
        "The word death hidden inside a paragraph sentence.",
        'The word death placed inside a written sentence represents a "Death sentence".'
    ),
    1644: (
        "Metaphor",
        "Rebus: Metaphor",
        "What figure of speech does this visual rebus represent?",
        "Count how many times the word META appears.",
        'Four repetitions of the word META: Meta four sounds like "Metaphor".'
    ),
    1665: (
        "Captain Jack Sparrow",
        "Rebus: Captain Jack Sparrow",
        "What famous movie character does this visual rebus represent?",
        "Numbers 1 to 10 with Jack and a sparrow bird in the middle.",
        'Visual rebus showing Jack and a sparrow among numbers up to ten: "Captain Jack Sparrow".'
    ),
    1668: (
        "Spider-Man: Far From Home",
        "Rebus: Spider-Man: Far From Home",
        "What superhero movie title is shown here?",
        "Spider-Man placed far away from a house/home icon.",
        'Visual rebus showing Spider-Man far away from home: "Spider-Man: Far From Home".'
    ),
    1681: (
        "Extraterrestrial",
        "Rebus: Extraterrestrial",
        "What sci-fi term does this visual rebus represent?",
        "The word EXTRA placed directly over the letter T.",
        'Wordplay on EXTRA over T (Extra-T) representing "Extraterrestrial".'
    ),
    1687: (
        "One at a time",
        "Rebus: One at a time",
        "What common idiom does this visual rebus represent?",
        "A sequence of 1s placed over a clock/time.",
        'The numeral 1 repeated alongside a clock face represents "One at a time".'
    ),
    1691: (
        "Once in a blue moon",
        "Rebus: Once in a blue moon",
        "What idiom does this visual rebus represent?",
        "Look at the word MOON and the color of its letters.",
        'The word MOON printed in blue letters represents the classic idiom "Once in a blue moon".'
    )
}

for qid, (ans, title, prompt, hint, exp) in updates.items():
    cur.execute('''
        UPDATE questions
        SET answer = ?,
            title = ?,
            prompt_text = ?,
            hint = ?,
            explanation = ?,
            review_status = 'unrated'
        WHERE id = ?
    ''', (ans, title, prompt, hint, exp, qid))

# Also fix the typo in 1626: 'History repeats iteself' -> 'History repeats itself'
cur.execute("UPDATE questions SET answer = 'History repeats itself', title = 'Rebus: History repeats itself' WHERE id = 1626")

conn.commit()
conn.close()
print(f'Successfully updated all {len(updates)} questions and images in DB!')

