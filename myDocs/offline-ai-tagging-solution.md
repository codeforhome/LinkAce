# Offline AI Tagging Solution for LinkAce
**Date:** December 19, 2025
**Status:** Phase 1 implemented; Phase 2 web UI implemented (basic)
**Priority:** High

## Problem Statement
LinkAce currently has basic auto-tagging via meta keyword extraction, but users want more intelligent, automated tag suggestions without sending data to external services.

## Proposed Solution: Offline AI Workflow

### Overview
Create an offline workflow where users can export bookmarks/links, run a local AI workflow (or any chat interface that stays on-device), and import AI-generated tag suggestions back into LinkAce.

This design deliberately keeps LinkAce out of the “AI business”:
- LinkAce produces a deterministic export for the AI to read.
- The user controls the AI tool and the data flow.
- LinkAce imports a deterministic, reviewable tag mapping.

### Reality Check (Current Repo State)
Phase 1 artisan commands exist now (`links:export-for-ai`, `ai:generate-prompt`, `ai:import-tags`). A basic Phase 2 web UI is also implemented at `GET /ai-tagging` with export/import actions.

### Current LinkAce Export Capabilities
- **HTML Export**: Netscape bookmark format (browser-compatible)
- **CSV Export**: Includes URL, title, description, tags, lists, created date
- **Existing Routes**:
  - `GET /export` - Export form
  - `POST /export/html` - HTML export
  - `POST /export/csv` - CSV export

### Proposed Implementation

#### 1. Enhanced Export Command for AI Processing
**Command:** `php artisan links:export-for-ai`

**Purpose:** Export bookmarks in AI-optimized format

**Options:**
- `--format=json|csv|markdown` - Output format (default: json)
- `--limit=N` - Limit number of bookmarks exported (default: all)
- `--untagged-only` - Export only bookmarks without any tags
- `--exclude-broken` - Exclude bookmarks with broken/dead links
- `--output=FILE` - Save to file instead of stdout

**Output Format Options:**
- JSON (structured for AI processing)
- CSV (enhanced with AI hints)
- Markdown (human-readable for manual review)

**Fields to Include:**
- URL
- Title
- Description
- Current tags (if any) - **Important for AI context**
- Domain/category hints
- Link status (ok, broken, moved) - **For filtering broken links**
- Created date
- Last checked date

**Important Note on “Content Preview”:**
LinkAce does not currently store a page-content snapshot for links. If a content preview is desired, it should either:
- be omitted entirely (recommended for Phase 1), or
- be generated via an optional, explicit crawler step (not part of “offline” unless it is strictly local and user-controlled).

**Stable Identifier Recommendation:**
Include LinkAce’s internal link `id` in exports and support importing by `id` (optionally with URL as a sanity check). Importing by URL alone is more fragile if URLs change or duplicates exist.

**AI Context Strategy:**
- Include existing tags in export to help AI understand current categorization
- AI can suggest improvements to existing tags or additional relevant tags
- AI can propose completely new tags based on content analysis
- Exclude broken links to focus AI processing on valid, accessible content
- Treat exported `current_tags` as the preferred vocabulary (reuse them where possible; add only missing tags)

**Example JSON Output:**
```json
[
  {
    "id": 123,
    "url": "https://laravel.com/docs",
    "title": "Laravel Documentation",
    "description": "Official Laravel framework documentation",
    "current_tags": [],
    "domain": "laravel.com",
    "category_hint": "documentation"
  }
]
```

#### 2. AI Prompt Template Generator
**Command:** `php artisan ai:generate-prompt`

**Purpose:** Generate structured prompts for AI chat interfaces

**Features:**
- Customizable criteria (URL analysis, content-based, domain-based)
- Multiple output formats
- Batch processing hints

**Example Generated Prompt:**
```
I have these bookmarks. For each one, suggest 3-5 relevant tags based on:
- URL structure and domain
- Title and description content
- Common categorization patterns
- IMPROVE existing tags if they need better categorization
- ADD new relevant tags that would be useful for organization

Guidelines:
- Keep existing tags that are still relevant
- Suggest improvements to poorly named or overly generic tags
- Add specific, actionable tags (e.g., "javascript" instead of "code")
- Consider the bookmark's purpose and context
- Create new tags if no existing ones fit well

Bookmarks:
1. Title: "Laravel Documentation"
   URL: https://laravel.com/docs
   Description: "Official Laravel framework documentation"
   Current tags: ["php", "framework"]

2. Title: "GitHub - user/repo"
   URL: https://github.com/user/repo
   Description: "My project repository"
   Current tags: ["development"]

Please suggest tags in this format:
ID URL: existing_tag1, existing_tag2, new_suggested_tag1, new_suggested_tag2

For each bookmark, include:
- All relevant existing tags (keep good ones)
- Improved versions of existing tags if needed
- New tags that would enhance organization
```

#### 3. AI Tag Import Command
**Command:** `php artisan ai:import-tags`

**Purpose:** Parse and import AI-generated tag suggestions

**Features:**
- Parse various AI response formats
- Dry-run mode for review
- Conflict resolution (merge/overwrite)
- Validation and error handling

**Supported Input Formats:**
- URL: tag1, tag2, tag3 (mixed existing and new tags)
- JSON structured responses with tag metadata
- CSV format with tag columns

**Tag Processing Rules:**
- **Existing Tags**: Matched by name, preserves tag IDs and metadata
- **New Tags**: Created with default visibility and user association
- **Duplicate Handling**: Skip duplicate tags within same bookmark
- **Validation**: Enforce LinkAce’s existing tag constraints (at minimum: required and unique per user); optionally add stricter sanitization if desired

**Options:**
- `--dry-run`: Show what would be changed without applying
- `--merge`: Merge with existing tags instead of replacing
- `--create-tags`: Auto-create new tags if they don't exist (default: true)
- `--skip-existing`: Don't modify bookmarks that already have tags

**Tag Creation Logic:**
- **Existing Tags**: Recognized by exact name match
- **New Tags**: Automatically created if `--create-tags` is enabled
- **Tag Validation**: Sanitize tag names, prevent duplicates
- **User Permissions**: Respect user's tag creation permissions

#### 4. Web Interface Integration
**Routes Implemented:**
- `GET /ai-tagging` - AI tagging workflow page
- `POST /ai-tagging/export` - Download export (calls `links:export-for-ai`)
- `POST /ai-tagging/import` - Upload AI results and generate a preview (no changes applied)
- `POST /ai-tagging/apply` - Apply changes from the latest preview (calls `ai:import-tags`)

**UI Features:**
- Step-by-step workflow guide
- Export format selection
- Prompt template display (copy/paste on export page)
- Import preview and explicit apply confirmation
- Progress tracking

**Navigation Placement (Implemented):**
- “AI Tag Export” and “AI Tag Import” are added to the profile-name dropdown menu, linking to `GET /ai-tagging` (with anchors to jump to the relevant section).
- Rationale: this is a power-user/admin workflow (not a daily navigation item), but should remain easy to find.

### Technical Implementation Details

#### Database Considerations
- No new tables required (uses existing links/tags relationships)
- Consider adding `ai_suggested_tags` JSON field for tracking suggestions
- Audit log integration for tag changes

#### Security & Privacy
- All processing offline - no data sent externally
- User controls AI interaction completely
- Input validation for imported tags
- Sanitization of AI responses

#### Performance Considerations
- Batch processing for large bookmark collections
- Memory-efficient streaming for exports
- Background job support for imports

### User Workflow

1. **Export Phase:**
   ```bash
   # Export all bookmarks for AI processing
   php artisan links:export-for-ai --format=json > bookmarks.json
   
   # Export only untagged bookmarks (recommended for initial tagging)
   php artisan links:export-for-ai --format=json --untagged-only > untagged_bookmarks.json
   
   # Export untagged bookmarks excluding broken links (optimal for AI processing)
   php artisan links:export-for-ai --format=json --untagged-only --exclude-broken > clean_untagged.json
   
   # Export limited batch for testing
   php artisan links:export-for-ai --format=json --limit=50 --untagged-only > test_batch.json
   
   # Save directly to file
   php artisan links:export-for-ai --format=markdown --untagged-only --output=bookmarks.md
   ```

2. **AI Processing Phase:**
   - Copy JSON data to AI chat interface (includes current tags for context)
   - Use generated prompt template that instructs AI to:
     - Keep relevant existing tags
     - Improve poorly named tags
     - Add new specific tags
     - Consider bookmark purpose and context
   - AI analyzes each bookmark individually, considering:
     - Current tag quality and relevance
     - Missing categorization opportunities
     - Content and URL analysis for new tag suggestions
   - Save AI response with improved tag suggestions

3. **Import Phase:**
   ```bash
   # Review changes first
   php artisan ai:import-tags --dry-run ai_response.txt

   # Apply changes
   php artisan ai:import-tags --merge ai_response.txt
   ```

### Alternative Implementation Options

#### Option A: Integrated AI Interface
- Embed local AI model (Ollama, GPT4All)
- Process entirely within LinkAce
- More complex, requires AI model management

#### Option B: Browser Extension
- Chrome/Firefox extension for AI tagging
- Direct browser integration
- Separate from core LinkAce

#### Option C: Desktop Application
- Electron app for offline AI processing
- More user-friendly interface
- Platform-specific

### AI Tag Intelligence Strategy

#### How AI Processes Existing Tags
- **Context Awareness**: AI receives current tags as context for each bookmark
- **Quality Assessment**: Evaluates if existing tags are specific enough or well-named
- **Improvement Suggestions**: Proposes better tag names (e.g., "javascript" instead of "code")
- **Gap Analysis**: Identifies missing categorization opportunities

#### Tag Creation and Management
- **Automatic Creation**: New tags are created automatically when AI suggests them
- **Validation**: Tag names are normalized/sanitized and validated against LinkAce’s tag constraints
- **Deduplication**: Prevents duplicate tags within the same bookmark
- **User Permissions**: Respects LinkAce's tag visibility and permission system

#### Example AI Decision Process
```
Input Bookmark:
- URL: https://laravel.com/docs/8.x/eloquent
- Title: "Eloquent ORM"
- Current Tags: ["php", "database"]
- Description: "Laravel's ORM for database interactions"

AI Analysis:
1. Keep "php" (relevant framework language)
2. Keep "database" (accurate category)
3. Add "laravel" (specific framework)
4. Add "orm" (specific technology)
5. Add "eloquent" (Laravel-specific term)
6. Consider removing generic tags if too many

Output: ["php", "database", "laravel", "orm", "eloquent"]
```

### Benefits of Chosen Approach

✅ **Completely Offline** - No data sent to external services
✅ **User Control** - Choose any AI interface
✅ **Flexible** - Works with current/future AI models
✅ **Reviewable** - Dry-run and confirmation steps
✅ **Extensible** - Easy to add new AI formats/prompts
✅ **Privacy-Focused** - All processing local  
✅ **Efficient** - Filter untagged bookmarks, limit batch sizes, exclude broken links  
✅ **Testable** - Small batch exports for validation  
✅ **AI-Smart** - Considers existing tags and suggests intelligent improvements

### Risks & Mitigations
- **Wrong/overly-broad tags**: default to `--dry-run`, encourage `--merge`, rely on audit trail for review/rollback
- **Ambiguous matching (URL changes/duplicates)**: export/import using link `id` as the primary key
- **Large exports/imports**: chunk/paginate exports and process imports in batches (optionally via queue jobs)
- **Tag spam / inconsistent casing**: normalize tags consistently (trim, collapse spaces); optionally add allow/deny lists

### Testing Strategy

- Unit tests for export/import commands
- Integration tests with sample AI responses
- User acceptance testing with real bookmark data
- Performance testing with large datasets

### Future Enhancements

- AI model integration (Ollama, local LLMs)
- Tag suggestion confidence scoring
- Bulk tag management interface
- Export templates for different AI models
- Integration with browser bookmark managers

### Implementation Priority

1. **Phase 1:** Core export/import commands
2. **Phase 2:** Web interface integration (basic) ✅
3. **Phase 3:** Advanced features (confidence scoring, templates)
4. **Phase 4:** AI model integration (optional)

### Success Metrics

- Successful export/import of 1000+ bookmarks
- Accurate AI tag parsing (>95% success rate)
- User workflow completion time <5 minutes
- No data loss during import operations
- `--limit` and `--untagged-only` options work correctly
- `--exclude-broken` properly filters out dead links
- Export formats are properly structured for AI processing

---

**Next Steps:**
1. Review and approve this plan
2. Start with Phase 1 implementation
3. Create detailed technical specifications
4. Begin coding the export command
