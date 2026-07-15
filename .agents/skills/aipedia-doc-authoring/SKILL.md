---
name: aipedia-doc-authoring
description: Use this skill when creating, updating, reviewing, or migrating documentation under web/docs/webchat, including bilingual _en/_id pairs, YAML frontmatter, descriptions, tags, keywords, natural technical prose, search_docs discoverability, lexical retrieval, routing metadata, document placement, or documentation index regeneration. Apply it even when the request says only “add docs”, “write a guide”, “improve search results”, or “document this feature”.
---

# AIPedia documentation authoring

Create documentation that is useful to readers and reliably discoverable by `search_docs` without requiring embeddings.

## Workflow

1. Read `web/docs/webchat/AGENTS.md` and the closest category `README.md` before editing.
2. Search `web/docs/webchat/index.json` and existing docs for overlap. Update an existing topic instead of creating a synonym duplicate.
3. Choose one canonical lowercase kebab-case slug and create or update both `<slug>_en.md` and `<slug>_id.md`.
4. For fast-moving or contested topics, use the corpus research pipeline described in `web/docs/webchat/AGENTS.md`; do not write factual claims from model memory alone.
5. Copy the appropriate files from `assets/` and complete the metadata according to [references/metadata-and-retrieval.md](references/metadata-and-retrieval.md).
6. Write and review the body using [references/prose-quality.md](references/prose-quality.md). Keep claims and section order equivalent across the language pair, but localize prose, descriptions, and search keywords naturally.
7. Include substantive explanation, when-to-use, when-not-to-use, trade-offs, one concrete example, related internal docs, and references when external claims matter. Use only the sections the topic actually needs.
8. Run the platform-native validator on every changed pair. It has no Node or Python dependency.

   Linux, macOS, Git Bash, or WSL:

   ```bash
   bash .agents/skills/aipedia-doc-authoring/scripts/validate-docs.sh \
     web/docs/webchat/docs/path/topic_en.md \
     web/docs/webchat/docs/path/topic_id.md
   ```

   Windows PowerShell:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File `
     .agents/skills/aipedia-doc-authoring/scripts/validate-docs.ps1 `
     web/docs/webchat/docs/path/topic_en.md `
     web/docs/webchat/docs/path/topic_id.md
   ```

9. Regenerate the corpus routing artifacts:

   ```bash
   cd web/docs/webchat && node scripts/build-index.mjs
   ```

10. If the local webchat is running, rebuild its runtime index and test representative queries:

    ```bash
    docker compose exec -T app php artisan webchat:reindex-docs --sync
    ```

## Retrieval acceptance

Before finishing, define and manually check:

- Two natural queries that should retrieve the document, including one query that does not repeat the H1 verbatim.
- One bilingual query when the pair supports Indonesian and English users.
- One near-miss query that should not rank the document highly.
- Search results must be grounded in the document body; metadata routes retrieval but never substitutes for evidence.

## Non-negotiable rules

- Put routing metadata in YAML frontmatter; do not maintain aliases in application code when the document owns them.
- Treat the relative path without `_en.md`/`_id.md` as canonical identity and the first H1 as display title. Do not duplicate either as `name` or `title` metadata.
- Write a specific intent-oriented description, not a generic summary such as “Documentation about X”.
- Use tags only from the corpus taxonomy. Put acronyms, aliases, spelling variants, and likely user phrases in keywords.
- Do not keyword-stuff, repeat body paragraphs in metadata, or add unrelated popular terms to improve rank.
- Keep tags identical across `_en` and `_id`; localize description and keywords.
- Do not expose repository paths as actions for end users. Paths returned by tools are internal grounding metadata.
- Do not claim metadata-aware ranking is active until the runtime index has been regenerated and search behavior verified.

## Resources

- Read [references/metadata-and-retrieval.md](references/metadata-and-retrieval.md) whenever choosing metadata or changing search behavior.
- Read [references/prose-quality.md](references/prose-quality.md) before drafting or reviewing document prose.
- Copy `assets/topic_en.md.template` and `assets/topic_id.md.template` for new topics.
- Run `scripts/validate-docs.sh` on Bash-compatible systems or `scripts/validate-docs.ps1` on Windows PowerShell after every metadata or bilingual-pair change.
