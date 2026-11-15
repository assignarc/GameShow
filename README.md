# 🍁 Fall Party Rebus Game Show — Installation & Complete User Guide

A full-featured, file-database-driven **Visual Rebus Game Show** web application built with **Symfony** and **SQLite**. Designed for hosting high-energy family & couples trivia championships on a big-screen TV or projector, controlled live from a laptop or **iPhone Remote**.

---

## 📑 Table of Contents

1. [Installation & Setup Guide](#-part-1-installation--setup-guide)
   - [Prerequisites](#11-prerequisites)
   - [Install Dependencies](#12-clone--install-dependencies)
   - [Database Initialization](#13-initialize-the-sqlite-database)
   - [Starting the Server (Desktop + iPhone Wi-Fi)](#14-start-the-application-server)
   - [Application URL Directory](#15-application-url-directory)
2. [Standard Operating Procedures (Step-by-Step User Guide)](#-part-2-standard-operating-procedures-step-by-step-user-guide)
   - [SOP 1: How to Create & Manage a Game Show](#sop-1-how-to-create--manage-a-game-show)
   - [SOP 2: How to Create Teams & Change Team Icons](#sop-2-how-to-create-teams--change-team-icons)
   - [SOP 3: Understanding Puzzle Statuses — Starred vs. Included vs. Skipped vs. Excluded](#sop-3-understanding-puzzle-statuses--starred-vs-included-vs-skipped-vs-excluded)
   - [SOP 4: How to Review Puzzles 1-by-1 (Review Deck)](#sop-4-how-to-review-puzzles-1-by-1-review-deck)
   - [SOP 5: How to Create, Edit & Crop Puzzles](#sop-5-how-to-create-edit--crop-puzzles)
   - [SOP 6: How to Change Rounds & Choose Different Puzzles](#sop-6-how-to-change-rounds--choose-different-puzzles)
   - [SOP 7: How to Handle & Resolve Duplicate Puzzles](#sop-7-how-to-handle--resolve-duplicate-puzzles)
   - [SOP 8: How to Customize Tournament Rules](#sop-8-how-to-customize-tournament-rules)
   - [SOP 9: How to Host the Live Game Show (Stage-by-Stage Walkthrough)](#sop-9-how-to-host-the-live-game-show-stage-by-stage-walkthrough)
3. [Keyboard Shortcuts Reference](#-part-3-keyboard-shortcuts-reference)
4. [Testing & Verification](#-part-4-testing--verification)

---

## 🛠️ Part 1: Installation & Setup Guide

### 1.1 Prerequisites
Make sure the following are installed on your machine:
- **PHP 8.2+** (compatible with PHP 8.2, 8.3, 8.4, and 8.5)
  - Required extensions: `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `json`, `ctype`, `iconv`, `gd` (or `imagick` for image cropping)
- **Composer** (PHP dependency manager)
- **SQLite 3**
- *(Optional)* **Symfony CLI** (`brew install symfony-cli/tap/symfony-cli`)

> **macOS / Homebrew Note:** Ensure Homebrew is on your `PATH` before running terminal commands:
> ```bash
> export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
> ```

---

### 1.2 Clone & Install Dependencies

```bash
# 1. Navigate to the project folder
cd /Users/vishalkhapre/Documents/Code/GameShow

# 2. Install PHP dependencies via Composer
composer install
```

---

### 1.3 Initialize the SQLite Database

The application uses a zero-configuration embedded SQLite database located at `var/gameshow.db` (seeded from `data/master_questions.json`).

```bash
# Initialize schema and ensure master questions & default rules are loaded
php bin/console app:db:init
```

> **Data Safety Note:** Running `app:db:init` is non-destructive—it creates missing tables and only seeds if the database is empty. Your curated puzzles, review statuses, stars, and custom shows are preserved.

---

### 1.4 Start the Application Server

To allow both your **TV browser** and your **iPhone Host Remote** (via QR code over local Wi-Fi) to connect, bind the server to all network interfaces (`0.0.0.0`):

#### Option A: Using Symfony CLI (Recommended)
```bash
# Foreground
symfony server:start --allow-all-ip --allow-http

# Or run in the background (daemon mode)
symfony server:start -d --allow-all-ip --allow-http
```

#### Option B: Using PHP's Built-in Web Server
```bash
php -S 0.0.0.0:8000 -t public
```

Once started, open **`http://localhost:8000`** in your browser.

---

### 1.5 Application URL Directory

| Screen | URL | Purpose |
| :--- | :--- | :--- |
| **Dashboard** | `http://localhost:8000/` | View all game shows, create a new show, launch Arena/TV views, or reset state/questions |
| **Manage Show** | `http://localhost:8000/shows/{id}/manage` | Configure Teams, Icon Themes, Rounds, Assigned Questions, and Show Rules |
| **Host Arena** | `http://localhost:8000/shows/{id}/play` | Live MC control room (desktop or iPhone via QR) with secret answer key & scoring |
| **TV Display** | `http://localhost:8000/shows/{id}/display` | Audience big-screen presentation view (70% stage + 30% live leaderboard) |
| **Question Bank** | `http://localhost:8000/moderation` | Browse, search, filter, add, edit, crop, star, include/exclude, or bulk-manage puzzles |
| **1-by-1 Review Deck** | `http://localhost:8000/moderation/review` | Fast fullscreen curation queue with hotkeys (`1` Include, `2` Skip, `3` Exclude, `S` Star) |
| **Duplicate Inspector** | `http://localhost:8000/moderation/duplicates` | Detect and merge/delete duplicate puzzles across the master bank |
| **Master Rules** | `http://localhost:8000/rules` | Edit global default tournament rules inherited by new shows |

---

## 📖 Part 2: Standard Operating Procedures (Step-by-Step User Guide)

---

### SOP 1: How to Create & Manage a Game Show

#### A. Creating a New Game Show
1. Go to the **Dashboard** (`http://localhost:8000/`).
2. Click the **`+ Create New Game Show`** button in the top-right corner of the **Your Game Shows** section.
3. Fill in the modal fields:
   - **Game Show Name**: e.g., *Fall Party Rebus Championship 2026*
   - **Subtitle / Theme**: e.g., *Couples Trivia Night*
   - **Default Question Timer**: Default countdown seconds per puzzle (e.g., `25` seconds).
   - **Puzzle Pool Filter** *(Checkbox)*:
     - Checked (`⭐ Only Select Starred Puzzles`): Only puzzles that are both **Starred (`⭐`) AND Included (`👍`)** will be picked.
     - Unchecked: Picks **Starred & Included** puzzles first, then fills remaining slots with **Unstarred Included** puzzles.
   - **Rapid Fire Bonus Round** *(Checkbox)*: Automatically creates a final **⚡ Rapid Fire Round** containing all remaining Starred & Included puzzles not used in earlier rounds.
4. Click **`Create & Configure Show`**. The app automatically sets up the 4-round Championship template and populates non-duplicate questions.

#### B. Managing an Existing Show from the Dashboard
On the Dashboard, each Game Show is displayed as a full-width horizontal card with two action bars:
- **Primary Launch Controls (Right)**:
  - **`🚀 Launch Arena`**: Opens the Host Control Room (`/shows/{id}/play`).
  - **`📺 TV View`**: Opens the big-screen Audience Display (`/shows/{id}/display`) in a new tab.
  - **`⚙️ Teams & Rounds`**: Opens the Manage Show configuration page (`/shows/{id}/manage`).
  - **`📱 iPhone QR`**: Displays a scannable QR code to open the Host Arena on your iPhone over local Wi-Fi.
- **Secondary Utility Strip (Bottom)**:
  - **`✏️ Edit Settings`**: Change the show name, subtitle, default timer, or Starred-only mode.
  - **`🔄 Reset Game Show`**: Resets all team scores to `0` and returns the show to the **Welcome Splash Screen** while **keeping all your assigned round questions and custom ordering intact**.
  - **`🎲 Reset Questions`**: Draws a **fresh random set of puzzles** across all rounds without resetting your round settings or team scores.
  - **`🗑️ Delete`**: Permanently deletes the game show.

---

### SOP 2: How to Create Teams & Change Team Icons

1. From the Dashboard, click **`⚙️ Teams & Rounds`** on your show card (or navigate to `/shows/{id}/manage`).
2. Ensure you are on the **`👥 Teams`** tab (supports up to **12 teams/couples**).

#### A. Adding a New Team / Couple
1. Click **`+ Add Couple / Team`**.
2. Enter the **Team Name** (e.g., *Ajane Family*) and **Couple / Member Names** (e.g., *Prajakta and Avinash*).
3. Select an icon and theme color from the categorized **Team Icon Picker** (see below).
4. Click **`Add Team`**.

#### B. Changing Icons for All Teams in One Click (Theme Switcher)
At the top of the **`👥 Teams`** tab, use the **`🎨 Quick Apply Icon Theme to All Teams`** bar to re-theme every team in the show with a single click:
- **`🥃 Liquors & Spirits`**: Assigns *Single Malt (`🥃`), Red Wine (`🍷`), Martini (`🍸`), Champagne (`🍾`), Margarita (`🍹`), Craft IPA (`🍺`), Prosecco (`🥂`), Vodka/Shots (`🍶`)* across *Single Malt Flight* & *Cocktail Flight*.
- **`🏹 Ramayana`**: Assigns *Kodanda Bow (`🏹`), Ayodhya Diya (`🪔`), Janaki Lotus (`🪷`), Royal Crown (`👑`), Sanjeevani Mountain (`⛰️`), Veer Jatayu (`🦅`), Vijay Shankha (`🐚`), Suryavansh Sun (`☀️`)* across *Ayodhya Flight* & *Panchavati Flight*.
- **`🪔 Desi Party`**: Assigns *Golden Diya (`🪔`), Royal Peacock (`🦚`), Sacred Lotus (`🪷`), Alphonso Mango (`🥭`), Cricket Bat (`🏏`), Royal Tiger (`🐅`), Desi Dhol (`🥁`), Mumbai Auto (`🛺`)*.
- **`🪐 Planets & Cosmic`**: Assigns *Surya/Sun (`☀️`), Shani/Saturn (`🪐`), Prithvi/Earth (`🌍`), Chandra/Moon (`🌕`), Mangal/Mars (`🔥`), Shukra/Venus (`🌟`), Guru/Jupiter (`⚡`), Comet (`☄️`)*.
- **`🍁 Fall Harvest`**: Assigns *Maple Leaf (`🍁`), Pumpkin (`🎃`), Apple (`🍎`), Cozy Scarf (`🧣`), Acorn (`🌰`), Fall Pie (`🥧`), Firewood (`🪵`), Tractor (`🚜`)*.

#### C. Changing a Single Team's Icon, Name, or Members
1. On any team card in the **`👥 Teams`** tab, click the **`✏️` (Edit)** button.
2. In the **Edit Team** modal, click any of the **Theme Filter Tabs** (`🥃 Liquors & Spirits`, `🏹 Ramayana`, `🪔 Desi Party`, `🪐 Planets`, `🍁 Fall & Games`, or `✨ All Icons`).
3. Click any icon tile—the live circular avatar preview at the top of the modal updates immediately with that icon and its signature border color.
4. Click **`Save Changes`**.

---

### SOP 3: Understanding Puzzle Statuses — Starred vs. Included vs. Skipped vs. Excluded

Every puzzle in the Question Bank (`/moderation`) has two independent attributes that control whether and how it appears in game shows:

#### 1. Review Status (`review_status` + `is_active`)

| Status Badge | Name | Included in Game Shows? | Meaning & When to Use |
| :---: | :--- | :---: | :--- |
| **👍 Included** | `included` (`is_active = 1`) | **✅ YES** | Approved, verified puzzles eligible to be played in game shows. |
| **⏭️ Skipped / Pending** | `skipped` or `pending` | **❌ NO** | Puzzles you haven't decided on yet or skipped during review. Never drawn into shows until marked **Included**. |
| **🚫 Excluded** | `excluded` (`is_active = 0`) | **❌ NO** | Rejected puzzles (too obscure, low quality, or unwanted). Kept in the database so they aren't re-imported, but strictly blocked from all game shows. |

#### 2. Starred Status (`is_favorite = 1`)

| Badge | Name | How It Affects Game Shows |
| :---: | :--- | :--- |
| **⭐ Starred** | Favorite / Priority (`is_favorite = 1`) | **Highest Priority.** When a game show selects questions for a round, **Starred + Included** puzzles are always picked **first** before any unstarred Included puzzles. |
| **☆ Unstarred** | Standard (`is_favorite = 0`) | Used as secondary fill if a round needs more questions than the available Starred pool (unless the show has **`⭐ Only Select Starred Puzzles`** enabled). |

#### Summary Selection Hierarchy in a Game Show
1. **If `⭐ Only Select Starred Puzzles` is ON** (in Manage Show):
   - Only puzzles that are **BOTH `⭐ Starred` AND `👍 Included`** are ever used.
2. **If `⭐ Only Select Starred Puzzles` is OFF**:
   - **1st Priority**: `⭐ Starred` + `👍 Included`
   - **2nd Priority**: `☆ Unstarred` + `👍 Included`
   - **Never Used**: `⏭️ Skipped`, `⏳ Pending`, and `🚫 Excluded` puzzles are **never** selected.

---

### SOP 4: How to Review Puzzles 1-by-1 (Review Deck)

To rapidly curate puzzles before your party:
1. Click **`🔍 Review Queue`** in the top navigation bar (or visit `/moderation/review`).
2. Use the filter pills at the top to filter by:
   - **Review Status**: `All`, `Pending / Skipped`, `Included`, `Excluded`, or `⭐ Starred Only`
   - **Category** and **Difficulty** (`Simple`, `Medium`, `Hard`)
3. For each puzzle card on screen, you see the full image, answer, alternate answers, category, and difficulty:
   - Click **`👍 Include`** (or press `1`) to approve it for game shows.
   - Click **`⏭️ Skip`** (or press `2`) to defer it for later (kept out of shows).
   - Click **`🚫 Exclude`** (or press `3`) to reject it permanently from shows.
   - Click **`⭐ Star`** (or press `S`) to mark/unmark it as a top-priority favorite.
   - Click **`✏️ Edit / Crop`** (or press `E`) to edit the answer, category, or crop the image right inside the review deck.

---

### SOP 5: How to Create, Edit & Crop Puzzles

#### A. Creating a New Puzzle
1. Go to the **Question Bank** (`/moderation`).
2. Click **`+ Add New Puzzle`** in the top-right header.
3. Fill in the details:
   - **Answer (Solution)**: The exact phrase teams must guess (e.g., `FALL PARTY`).
   - **Alternate Acceptable Answers**: Comma-separated variations (e.g., `AUTUMN PARTY`).
   - **Category**: Choose from the dropdown (e.g., *Common Idioms & Sayings*, *Movies & Pop Culture*, *Food & Drink*, etc.).
   - **Difficulty**: `Simple`, `Medium`, or `Hard`.
   - **Hint / Explanation**: Optional clue for the host.
   - **Puzzle Image**:
     - **Upload Image File**: Upload a `.jpg`, `.png`, `.webp`, or `.gif` file from your computer, **OR**
     - **Image URL / Path**: Paste an image URL or local `/uploads/...` path.
4. Click **`Create Puzzle`**. New puzzles created manually are automatically marked **`👍 Included`**.

#### B. Editing a Puzzle's Answer, Category, or Difficulty
1. In `/moderation`, find the puzzle card using the search bar or category/status filters.
2. Click **`✏️ Edit`** on the card.
3. Update the **Answer**, **Category**, **Difficulty**, **Hint**, or **Review Status**, and click **`Save Changes`**.

#### C. Cropping an Image or Hiding Spoilers/Watermarks (Built-in Image Studio)
Some sourced rebus images have answers printed at the bottom or watermarks in the corner:
1. Click **`✏️ Edit`** (or **`✂️ Crop`**) on any puzzle card in `/moderation` or `/moderation/review`.
2. In the **Image Crop & Redact Studio**:
   - **Crop Mode**: Drag the crop handles to trim out top/bottom borders or answer text.
   - **Paint / Redact Mode**: Choose a brush color (e.g., background match) and paint over any watermark or spoiler text.
3. Click **`💾 Save Cropped Image`**.
4. *(Safety Backup)*: The original untouched image is automatically backed up in `original_media_path`. You can click **`↩️ Restore Original Image`** at any time to undo all crops/edits.

#### D. Bulk Actions (Multi-Select)
1. In `/moderation`, check the selection box on multiple puzzle cards (or click **`Select All on Page`**).
2. Use the floating bottom bar to **Batch Include (`👍`)**, **Batch Exclude (`🚫`)**, **Batch Star (`⭐`)**, **Move to Category**, or **Batch Delete (`🗑️`)**.

---

### SOP 6: How to Change Rounds & Choose Different Puzzles

Open your show's configuration page at **`/shows/{id}/manage`** and switch to the **`🔄 Rounds & Question Categories`** tab.

#### A. Adding, Editing, Reordering, or Deleting Rounds
- **Edit Round Settings**: Click **`✏️ Edit Round`** on any round card to change:
  - **Round Name** (e.g., *Round 1: Warm-Up All-Play*)
  - **Round Format**:
    - `All Teams Open Play`: Every team plays every puzzle.
    - `Head-to-Head Flight Matchups`: Teams face off in paired flights.
    - `⚡ Rapid Fire`: Automatically loads all remaining Starred & Included puzzles not used in other rounds.
  - **Question Timer** (`10s` – `120s`), **Points per Correct Guess** (e.g., `+10`), and **Steal Points** (e.g., `+5`).
  - **Advancement / Elimination**: Choose `All Teams Advance` or `Eliminate: Top X Teams Advance` (automatically eliminates bottom teams when the round ends).
  - **Category Quotas**: Set how many puzzles of each Category and Difficulty (`Any`, `Simple`, `Medium`, `Hard`) belong in this round.
- **Reorder Rounds**: Use the **`⬆️`** and **`⬇️`** buttons on any round header to change round order.
- **Add a New Round**: Click **`+ Add New Round`** at the top of the Rounds tab.

#### B. Choosing Different Puzzles for a Round (4 Ways)
Inside each Round card, you will see the **Assigned Round Questions** grid showing the exact puzzles queued for that round:

1. **Manually Pick Specific Puzzles (`🧩 + Add Puzzles to Round`)**:
   - Click **`+ Add Puzzles`** on the round header.
   - In the **Question Picker Modal**, search by keyword/answer or filter by **Category**, **Difficulty**, and **Starred**.
   - Puzzles already assigned to any round in this show are automatically flagged/hidden so you can never accidentally add a duplicate.
   - Click multiple puzzle cards to select them (or click **`+ Add`** on a single card) and click **`+ Add Selected Puzzles`**.
2. **Swap a Single Puzzle for Another Random One (`🎲 Swap`)**:
   - Don't like one specific puzzle in a round? Click the **`🎲`** button on that puzzle's mini-card to immediately replace it with a fresh, non-duplicate puzzle from the pool.
3. **Change the Order of Puzzles Within a Round (`⬅` / `➡`)**:
   - Click **`⬅`** or **`➡`** on any puzzle card in the round to move it earlier or later in the sequence.
4. **Remove a Puzzle (`✕`) or Randomize All Rounds (`🎲 Reset Questions`)**:
   - Click **`✕`** on a puzzle card to remove it from the round.
   - Or click **`🎲 Reset Questions`** in the top header to draw a completely fresh random set of puzzles across all rounds.

---

### SOP 7: How to Handle & Resolve Duplicate Puzzles

#### A. Automatic Duplicate Prevention Inside Game Shows
You never have to worry about the same puzzle appearing twice during a party:
- Whenever rounds are populated, swapped, or manually edited, the engine checks both **Question ID (`question_id`)** and **Normalized Answer Text** (ignoring case, punctuation, and leading articles like *"a"*, *"an"*, *"the"*).
- If two different images have the exact same answer (e.g., two different graphics for `"ONCE IN A BLUE MOON"`), only **one** can be assigned to a show.

#### B. Cleaning Up Duplicates in the Master Question Bank (`/moderation/duplicates`)
1. Click **`👯 Duplicates`** in the top navigation bar (or visit `/moderation/duplicates`).
2. The **Duplicate Detector** scans the entire database and groups potential duplicates into three confidence tiers:
   - **Exact Answer Match**: Identical normalized answers.
   - **High Similarity / Fuzzy Match**: Answers that differ only by a plural, hyphen, or minor spelling variation, or share the exact same image file.
3. For each duplicate pair displayed side-by-side:
   - **Compare Images, Statuses & Stars**: See which version has the cleaner graphic or better crop.
   - **`✅ Keep Left & Delete Right`** (or **`Keep Right & Delete Left`**): One click deletes the redundant copy and automatically transfers any `⭐ Star` or round assignment to the surviving puzzle.
   - **`✏️ Edit Answer`**: If two different puzzles accidentally have the same answer label, edit one inline.
   - **`🙅 Dismiss (Not a Duplicate)`**: Permanently marks the pair as distinct so it never flags them again.

---

### SOP 8: How to Customize Tournament Rules

1. **Global Default Rules (`/rules`)**:
   - Click **`📜 Rules`** in the top navigation bar to edit the master list of humorous/official house rules (e.g., *Rule #1: Host Has Final Authority*, *Rule #2: Host's Wife is Always Right*, *Rule #5: Bribing the Host*).
2. **Per-Show Rules (`/shows/{id}/manage` ➔ `📜 Rules & Briefing` Tab)**:
   - Every show has its own customizable rule list presented 1-by-1 on the TV screen before the game starts.
   - You can **Add**, **Edit**, **Reorder (`⬆️`/`⬇️`)**, or **Delete** rules for a specific show, or click **`🔄 Reset to Master Common Rules`** to restore the defaults.

---

### SOP 9: How to Host the Live Game Show (Stage-by-Stage Walkthrough)

#### Step 0: Dual-Screen Party Setup
1. Connect your laptop to the living room **TV or Projector** as an **Extended Display** (or via AirPlay / HDMI).
2. On the Dashboard (`http://localhost:8000/`), click **`📺 TV View`** and drag that browser window onto the TV screen in fullscreen mode (`F11` or `Ctrl+Cmd+F`).
   - *Tip: Click once anywhere on the TV window (or click the `🔊 Sound` button in the TV header) so the browser unlocks Web Audio on the TV screen.*
3. On your laptop—or on your **iPhone** by scanning the **`📱 iPhone QR`** code—open the **Host Arena** (`/shows/{id}/play`).

---

#### Stage 1: Welcome Splash Screen (`status = 'setup'`)
- **What's on TV**: Fullscreen **Khapre Party Welcome Splash Screen** with floating autumn/party particles and opening game-show theme music. The teams sidebar and timer are hidden so nothing is spoiled.
- **Host Action**: When guests are gathered, click **`👥 Next: Introduce Team 1 on TV`** (or press `Space` / `Enter`).

---

#### Stage 2: Competing Teams 1-by-1 Spotlight & Seating (`status = 'teams_intro'`)
- **What's on TV**:
  - Each family/couple is introduced **one at a time** in the center spotlight card (`TEAM 1 OF 8`) with a rising chime fanfare, their custom icon badge (`🥃`, `🍷`, `🍸`, etc.), couple names, and flight group.
  - As each team is introduced, they **take their seat** in the right-hand **Live Scores** sidebar (`1 / 8 Seated` ➔ `2 / 8 Seated` ➔ `8 / 8 Seated`), while unintroduced slots show reserved seat placeholders (`🪑 Seat #N • Awaiting Intro`).
- **Host Action**:
  - Click **`👥 Next Team (2/8) ➡`** as you announce and applaud each couple.
  - After the final team is seated, click **`📜 Next: Unveil Rules on TV ➡`**.

---

#### Stage 3: Tournament Rules 1-by-1 Briefing (`status = 'rules_intro'`)
- **What's on TV**: Displays `RULE 1 OF N` in a clean spotlight card with icon, title, and description.
- **Host Action**:
  - Click **`Next Rule ➡`** (or press `Space` / `Right Arrow`) to step through each rule 1-by-1 with a chime transition.
  - On the final rule, click **`🎯 Next: Sample Tryout Puzzle`**.

---

#### Stage 3.5: Standalone Sample Tryout Puzzle ("FALL PARTY") (`status = 'sample_puzzle'`)
- **What's on TV**:
  - Launches the standalone warm-up rebus puzzle (**`Y - T - R - A - P`** cascading down an autumn waterfall = **`FALL PARTY`**), labeled **`🎯 Sample Tryout Puzzle (Practice • No Points)`**.
  - Starts the live countdown timer with last-5-second warning ticks (`5..1s`) and time-up buzzer (`0s`) so teams experience exactly how the timer and reveal work.
  - *(Note: This puzzle is strictly hardcoded for the tryout stage and is never part of the Question Bank or main rounds).*
- **Host Action**:
  - Let teams call out guesses, then click **`💡 Reveal`** to show **`FALL PARTY`** with the correct-answer chime.
  - Click **`⏭️ Round 1 Info`** to proceed to the Round 1 Briefing.

---

#### Stage 4: Round Briefing & Explicit Start (`status = 'round_intro'`)
- **What's on TV**: Displays the **Round 1 Briefing & Rules** card showing the Timer (`25s`), Win Points (`+10 pts`), Steal Points (`+5 pts`), total puzzle count, and categories, with **`🎮 Waiting for Host to Start Round!`**.
- **Host Action**: Once everyone is ready, click **`🚀 Start Round 1`**.

---

#### Stage 5: Live Puzzle Play, Scoring & Round Progression (`status = 'active'`)
1. **Puzzle Appears & Timer Starts**:
   - The arena horn sounds, the visual rebus appears on the TV stage, and the countdown timer runs.
   - On your **Host Arena** (laptop or iPhone), the **🤫 HOST ANSWER KEY** card shows you the secret answer while it remains hidden on the TV.
2. **Revealing the Answer**:
   - When a team guesses correctly (or time runs out), click **`💡 Reveal`** (or press `Space`).
   - The answer banner pops onto the TV screen with a celebratory chime.
3. **Awarding Points (with TV Celebration Burst)**:
   - Click **`+10`** (Win), **`+5`** (Steal), or **`-5`** (Penalty) next to the team on the Host Scoreboard—or tap **`🏆 Award`** on mobile to open the Quick Award sheet.
   - **On the TV Screen**:
     - A celebratory **`+10 PTS • Team Name`** burst banner drops in at the top of the stage with the jackpot fanfare sound.
     - The winning team's row in the Live Scores sidebar glows emerald-gold, their score badge pops in 3D with a floating **`+10`** delta pill, and the leaderboard automatically re-sorts by rank (`🥇`, `🥈`, `🥉`).
4. **Advancing to Next Puzzle & End of Round**:
   - Click **`▶️ Next`** to launch the next puzzle in the round.
   - When the final puzzle of the round is finished, clicking **`▶️ Next`** automatically displays the **`🏁 END OF ROUND N`** announcement screen on TV (previewing the upcoming round's format and applying any Top-X eliminations when advancing).

---

## ⌨️ Part 3: Keyboard Shortcuts Reference

### Host Arena (`/shows/{id}/play`)
| Key | Action |
| :---: | :--- |
| `Space` or `Enter` | **Reveal Answer** (when a puzzle is active) or **Advance to Next Stage** (Splash ➔ Teams ➔ Rules ➔ Tryout ➔ Start Round) |
| `Right Arrow` or `N` | **Next Step / Next Team / Next Rule / Next Puzzle** |
| `Left Arrow` | **Previous Team** (during Team Intro), **Previous Rule** (during Rules Intro), or **Previous Question** |
| `P` | **Pause / Resume** Countdown Timer |
| `Escape` | Exit Rules Briefing overlay |

### 1-by-1 Review Deck (`/moderation/review`)
| Key | Action |
| :---: | :--- |
| `1` | Mark puzzle as **👍 Included** and advance |
| `2` | Mark puzzle as **⏭️ Skipped** and advance |
| `3` | Mark puzzle as **🚫 Excluded** and advance |
| `S` | Toggle **⭐ Starred** status |
| `E` | Open **✏️ Edit & Crop Studio** modal |
| `Space` | Toggle Show/Hide Answer |

---

## 🧪 Part 4: Testing & Verification

Run the Symfony container/Twig linters and the automated PHPUnit test suite at any time:

```bash
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

# 1. Validate Symfony service container and Twig templates
php bin/console lint:container
php bin/console lint:twig templates/

# 2. Run the full PHPUnit test suite (34 functional & integration tests)
php bin/phpunit
```
