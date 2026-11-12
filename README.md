# Fall Party Rebus Game Show Application

A file-database-driven, multimodal Rebus Game Show web application built with Symfony and SQLite.

## 🌟 Features

- **514 Curated Image & Media Rebus Puzzles:** Every single question uses authentic visual images (illustrations, photographic rebuses, and crisp typography cards). No plain-text-only riddles.
  - Sourced directly from **Reader's Digest**, **NIEHS Kids Brainteasers**, **Bored Panda Visual Challenges**, **Macaroni Kid North Shore**, **pmovva/rebus-puzzles**, and **ESL Vault**.
- **File Database Driven:** Zero external database server needed. Everything runs off SQLite (`var/gameshow.db`), backed up and exportable anytime to `data/master_questions.json`.
- **Live Host Arena with Synchronized Timer:**
  - Real-time countdown timer with danger/pulse warning as time expires.
  - One-click "Reveal Answer" banner and keyboard shortcuts (`Space` to reveal, `Right Arrow` for next question, `P` for pause).
  - Non-repeating question guarantee: questions asked in any show run are tracked and never repeated.
- **Projector / TV Presentation View (`/shows/{id}/display`):**
  - Clean, distraction-free widescreen view for the party TV/projector with giant timer, image viewport, and animated score ticker.
- **Flight Bracket & Elimination Rounds:**
  - Supports up to 12 teams/couples with custom team names, emojis, and flight conferences (Acorn & Pumpkin flights).
  - Configurable rounds: Open All-Play vs. Head-to-Head Flight Matchups, customizable timer per round, point values, steal points, and "Top X Advance" elimination rules.
- **Full Moderation & Question Bank (`/moderation`):**
  - Browse, filter by category/difficulty, search answers, favorite, edit hints, delete, or upload custom images and add new puzzles.
  - Export and import question databases as standard JSON.

---

## 🚀 How to Run from the Command Prompt

In your project directory (`/Users/vishalkhapre/Documents/Code/GameShow`):

```bash
# 1. Start the web server
php -S 127.0.0.1:8000 -t public
# (or with Symfony CLI: symfony serve -d)

# 2. Re-seed or reset database anytime from the master JSON
php bin/console app:db:init --reset

# 3. Run automated tests
php bin/phpunit
```

Open your browser to:
- **Dashboard & Game Shows:** [http://127.0.0.1:8000/](http://127.0.0.1:8000/)
- **500+ Question Moderation Bank:** [http://127.0.0.1:8000/moderation](http://127.0.0.1:8000/moderation)
- **Host Arena:** [http://127.0.0.1:8000/shows/1/play](http://127.0.0.1:8000/shows/1/play)
- **TV / Projector View:** [http://127.0.0.1:8000/shows/1/display](http://127.0.0.1:8000/shows/1/display)

