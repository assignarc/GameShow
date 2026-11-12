# 🍁 Fall Party Rebus Game Show Application

A file-database-driven, multimodal Rebus Game Show web application built with **Symfony** and **SQLite**. Designed for hosting high-energy visual trivia nights, couples championships, and holiday party tournaments on a big screen TV or projector.

---

## 🌟 Key Features

### 1. 🧩 Multimodal Master Question Bank (`/moderation`)
- **600+ Curated Multimodal Puzzles:** Authentic visual images (illustrations, photographic rebuses, vector SVGs, and spatial typography). No plain-text-only riddles.
- **Alphabet Wordplay & Sounds-Like Series:** Specially crafted letter sound cards (`Vegetable in Alphabet = P`, `Drink in Alphabet = T`, `Question in Alphabet = Y`, `Organ in Alphabet = I`, `Surprise in Alphabet = O`, `Math in Alphabet = X`, `Insect = B`, `Body of Water = C`, `Waiting Line = Q`, `Tree = U`) with zero-spoiler card designs.
- **Categorized & Tiered:** Grouped into 7 distinct categories (Food & Drink, Movies & Pop Culture, Common Idioms & Sayings, Science & Nature, Compound Words & Places, Sounds-like Alphabet, Wordplay & Riddles) across **Simple**, **Medium**, and **Hard** difficulty curves.
- **Multi-Select Bulk Operations:** Select multiple questions with a single click or "Select All on Page", with a floating bottom action bar to **Batch Delete**, **Batch Include**, or **Batch Exclude**.
- **1-by-1 Review Deck (`/moderation/review`):** Fullscreen distraction-free curation deck with answers and clues visible upfront, keyboard hotkeys (`1` Include, `2` Skip, `3` Exclude, `Space` toggle hide, `E` Edit), and AI image generation/upscaling.
- **Duplicate Detection & Merging (`/moderation/duplicates`):** Automated Levenshtein and token-based duplicate detector to merge and clean up duplicate questions.
- **JSON Import / Export:** One-click full export (`rebus_master_question_bank_YYYY-MM-DD.json`) and instant database seeding.

---

### 2. 🎬 Tournament & Game Show Engine
- **Custom Game Shows:** Create, edit, and update Game Show names, subtitles, and default timers directly from the Dashboard or Manage view.
- **Turnkey 1-Hour Championship Format:**
  - Standardized **4-Round, 38-Question Tournament** optimized for a 60-minute party schedule:
    - **Round 1: Warm-Up All-Play** (10 questions, 25s timer, 10 pts, open play).
    - **Round 2: Flight Battles** (10 questions, 25s timer, 15 pts, 5 pt steal, Acorn vs. Pumpkin Flights, Top 8 advance).
    - **Round 3: Gauntlet Elimination** (10 questions, 20s timer, 20 pts, 10 pt steal, Top 4 advance).
    - **Round 4: Podium Championship** (8 questions, 15s rapid-fire, 25 pts, 15 pt steal, crowns 🥇, 🥈, 🥉).
- **Flexible Round Management (`/shows/{id}/manage`):**
  - Add, edit, delete, and reorder rounds with **Move Up / Move Down** buttons.
  - Set custom category quotas and difficulty filters per round.
  - Configure point rewards, steal penalties, and advancement rules ("All Advance" or "Top X Advance").
- **One-Click Refresh / Reset:** Instant reset of all team scores to 0, clearing question history, and resetting the show back to Round 1 from the Dashboard, Manage Show, or Host Arena.
- **Smart Non-Repeating Question Selector:** Tracks asked questions across the show run so no puzzle is ever repeated during an event, while prioritizing approved/included questions.

---

### 3. 🚀 Live Host Arena (`/shows/{id}/play`)
- Dedicated command center for the host / MC:
  - Synchronized real-time countdown timer with danger/pulse warning as time runs down.
  - One-click answer reveal and keyboard shortcuts (`Space` to reveal, `Right Arrow` for next question, `P` to play/pause timer).
  - Award points directly to teams (+10, +15, +20, +25) and assign steal points (+5, +10, +15).
  - Matchup selector for head-to-head flight battles.
  - Real-time round progress counter.

---

### 4. 📺 Projector / Big Screen TV Display (`/shows/{id}/display`)
- Clean, distraction-free widescreen view optimized for large displays and projectors:
  - **Left Stage (70%):** Large visual rebus viewport (up to `78vh`), glowing category badges, round banner, dynamic countdown bar, and animated answer banner.
  - **Right Sidebar Scoreboard (30%):** Dedicated vertical scoreboard displaying real-time team rankings, flight tags, team emojis, medal icons (🥇 🥈 🥉), and score badges.
  - 1-second background state polling for seamless synchronization with the Host Arena.

---

### 5. 👥 Team & Flight Management
- Accommodates up to 12 teams/couples.
- Custom team names, couple/member names, avatar emojis, and flight brackets (e.g. *Acorn Flight* vs. *Pumpkin Flight*).

---

### 6. 📜 Master Common Rules & 1-by-1 Show Briefing
- **Central Master Rules Template (`/rules`):**
  - Manage standard tournament rules (title, explanation, icon emoji, sort order).
  - Seeded out of the box with 6 core rules: *Say What You See*, *Beat The Countdown Timer*, *Buzzer & Steal Rights*, *Tournament Bracket Stakes*, *Host Has Final Authority*, *No Phones & Pure Fun*.
- **Per-GameShow Inheritance & Customization (`/shows/{id}/manage` ➔ Tab "📜 Rules & Briefing"):**
  - When a new show is created, it automatically inherits the Master Common Rules.
  - Custom rules can be added, edited, reordered (Move Up / Down), or deleted per show without affecting other shows or the master template.
  - "Reset to Common Rules" button to instantly re-synchronize from the master template at any time.
- **1-by-1 Show Briefing Presentation:**
  - **Host Arena (`/shows/{id}/play`):** Click **📜 Rules Briefing** to enter briefing mode. Advance rules 1-by-1 (`[ ⬅ Prev Rule ]`, `[ Next Rule ➡ ]` or Keyboard `Space`/`ArrowRight`), previewing the audience card directly on the host screen, and launch immediately into Round 1 via **🚀 Start Round 1**.
  - **TV Display (`/shows/{id}/display`):** Displays a high-impact, fullscreen rule card with glowing emoji badge, large readable typography, step counter (`RULE X OF Y`), and progress dots synchronized in real-time with the host arena.

---

## 🛠️ First-Time Setup Instructions

### 1. Prerequisites
Ensure you have the following installed on your machine:
- **PHP 8.2 or higher** (tested with PHP 8.2, 8.3, 8.4, and 8.5)
  - Required PHP extensions: `pdo_sqlite`, `curl`, `mbstring`, `fileinfo`, `json`, `ctype`, `iconv`
- **Composer** (PHP dependency manager)
- **SQLite3**
- *(Optional)* **Symfony CLI** (`https://symfony.com/download`)

> **Mac / Homebrew Note:** If using macOS with Homebrew, ensure Homebrew's bin directory is in your `PATH`:
> ```bash
> export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
> ```

---

### 2. Clone and Install Dependencies

```bash
# 1. Clone the repository
git clone <repository-url> GameShow
cd GameShow

# 2. Install PHP dependencies
composer install
```

---

### 3. Initialize the Database (Pre-Loaded Questions)

The application uses an embedded SQLite database (`var/gameshow.db`). No MySQL, PostgreSQL, or external database server is required!

The database comes **pre-loaded with the complete set of curated visual rebus puzzles**. Existing puzzles are permanently preserved and will **never be wiped or reset**:

```bash
# Ensures SQLite database schema and tables are initialized (preserves all existing puzzles)
php bin/console app:db:init
```

> **Note:** There is **no reset option** for the database. If you wish to remove questions you do not like, you have full control to delete individual puzzles or batch delete multiple questions directly from the **Question Bank (`/moderation`)** interface.

---

### 4. Run the Application

You can run the web server using either the Symfony CLI or PHP's built-in web server. To control the show from your iPhone or mobile device over local Wi-Fi, bind to all interfaces:

#### Option A: Using Symfony CLI (Recommended for Local Wi-Fi & iPhone Access)
```bash
# Listen on all network interfaces with HTTP support for seamless iPhone scanning
symfony server:start --allow-all-ip --allow-http
```
Or run in background with `-d`:
```bash
symfony server:start -d --allow-all-ip --allow-http
```

#### Option B: Using PHP Built-in Server
```bash
# Listen on all local network interfaces on port 8000
php -S 0.0.0.0:8000 -t public
```

---

## 🧭 Application URL Guide

| Page / Feature | URL | Description |
| :--- | :--- | :--- |
| **Dashboard** | `http://127.0.0.1:8000/` | Overview of all game shows, global statistics, create show modal, quick refresh, and edit show name |
| **Manage Show** | `http://127.0.0.1:8000/shows/{id}/manage` | Configure teams (up to 12), reorder/edit/delete rounds, set category quotas, edit show details |
| **Host Arena** | `http://127.0.0.1:8000/shows/{id}/play` | MC dashboard with timer controls, reveal answer button, point awarding, and question navigation |
| **TV / Projector View** | `http://127.0.0.1:8000/shows/{id}/display` | Large projector view with 2-column layout: giant puzzle stage on the left, live scoreboard on the right |
| **Question Bank** | `http://127.0.0.1:8000/moderation` | Search, filter by category/difficulty, multi-select bulk delete, toggle included/excluded, add new puzzle |
| **1-by-1 Review Deck** | `http://127.0.0.1:8000/moderation/review` | Distraction-free review mode with answers visible upfront, keyboard hotkeys, and AI upscaler |
| **Duplicate Detector** | `http://127.0.0.1:8000/moderation/duplicates` | Detect fuzzy and exact duplicate questions across the question bank |

---

## ⌨️ Host & Review Keyboard Shortcuts

### Host Arena (`/shows/{id}/play`)
- `Space` : Reveal Answer
- `Right Arrow` : Next Question
- `P` : Play / Pause Timer
- `R` : Reset Timer to default seconds

### Review Deck (`/moderation/review`)
- `1` : Mark as **Included** (Active in show)
- `2` : **Skip** (Review later)
- `3` : Mark as **Excluded** (Rejected)
- `Space` : Toggle Hide / Show Solution & Clues
- `E` : Open Edit Modal

---

## 🧪 Running Automated Tests

The application includes a comprehensive test suite covering routing, question moderation, game state transitions, batch deletion, round management, and show configuration:

```bash
# Run all PHPUnit tests
php bin/phpunit

# Or via vendor binary
vendor/bin/phpunit
```

---

## 📁 Project Directory Structure

```text
GameShow/
├── assets/                  # CSS, JS, and frontend assets
├── bin/
│   └── console              # Symfony console CLI
├── config/                  # Symfony routes, packages, and framework configuration
├── data/
│   ├── master_questions.json # Master question bank JSON backup
│   └── puzzles/             # Original puzzle media source assets
├── public/
│   ├── index.php            # HTTP front controller
│   └── uploads/puzzles/     # Web-accessible puzzle images and SVGs
├── src/
│   ├── Command/             # CLI commands (app:db:init)
│   ├── Controller/          # GameShowController, ModerationController, HomeController
│   └── Service/             # GameDatabase, AiImageGenerator, DuplicateDetector
├── templates/
│   ├── base.html.twig       # Base application layout and navigation
│   ├── game/                # Host Arena, TV Display, and Manage Show templates
│   ├── home/                # Main dashboard template
│   └── moderation/          # Question bank grid, 1-by-1 review, and duplicates view
├── tests/                   # Automated PHPUnit tests (GameShowTest.php)
├── var/
│   └── gameshow.db          # Embedded SQLite database
└── composer.json            # PHP project dependencies and autoloader
```

---

## 💡 Customizing Your Party

1. **Change the Game Show Name & Theme:**
   - From the Dashboard or Manage view, click **✏️ Edit Details** to change the title (e.g. *"Fall Party Rebus Championship 2026"*), subtitle, or default timer.
2. **Apply 1-Hour Schedule:**
   - On the Manage view, click **⏱️ Apply 1-Hour Championship Format** to instantly configure the balanced 4-round schedule (38 questions total).
3. **Reset for a New Party:**
   - Click **🔄 Refresh / Reset Show** to wipe team scores to 0, clear used question history, and return to Round 1 without losing your configured teams and rounds.
