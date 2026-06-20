# myDocs

Planning notes and implementation references for local customizations and feature work in this repo.

## Start here
- `myDocs/engineering-notes.md` - **Future reference:** feature map, schema, env catalog, known
  gotchas (TRUSTED_HOSTS 400, phpunit cache, intelephense, merge regressions), and the upstream
  sync checklist. Read this before touching the custom features or merging upstream.

## AI Tagging
- `myDocs/ai-tagging-quick-ref.md` - Quick command/syntax reference (offline + online, canonical, re-tagging).
- `myDocs/offline-ai-tagging-solution.md` - Detailed design/architecture for the offline workflow.
- `myDocs/ai-tagging-improve.md` - Strategy notes: lean personal-scale tagging (canonical list + raw_tags;
  semantic search deliberately parked).

## Link Metadata
- `myDocs/link-metadata.md` - Title/description/thumbnail resolution: the provider chain
  (FxTwitter → standard → Jina), bot-wall detection, config, behaviour matrix, and how to add a
  provider for another site.
