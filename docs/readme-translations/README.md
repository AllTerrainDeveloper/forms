# Readme translations

Translations of `readme.txt` (the WordPress.org plugin page) for the plugin's
**Stable Readme** project on translate.wordpress.org. They cover the plugin name,
short description, description, installation, FAQ and screenshot captions. The
changelog is left in English.

| File | Locale | Directory |
| --- | --- | --- |
| `es_ES.po` | Spanish (Spain) | es.wordpress.org |
| `de_DE.po` | German | de.wordpress.org |
| `fr_FR.po` | French (France) | fr.wordpress.org |
| `pt_BR.po` | Portuguese (Brazil) | br.wordpress.org |
| `it_IT.po` | Italian | it.wordpress.org |
| `ja.po` | Japanese | ja.wordpress.org |
| `tr_TR.po` | Turkish | tr.wordpress.org |

These files are not part of the plugin package (`docs/` never ships).

## Importing

translate.wordpress.org builds the readme strings from the release the
`Stable tag` points to, so import after that release is out:

1. Open `https://translate.wordpress.org/projects/wp-plugins/allterrain-forms/stable-readme/<locale>/default/`
   (`es`, `de`, `fr`, `pt-br`, `it`, `ja`, `tr`).
2. **Import Translations** → choose the `.po` file.
3. A Project Translation Editor for that locale approves them. Plugin committers
   can request the editor role per locale; please have a native speaker read the
   text before approving.

Every msgid in these files was generated from `readme.txt` with the same
extraction rules translate.wordpress.org uses, so they match the project's
originals exactly. If `readme.txt` changes before release, regenerate them, or
the changed strings will import as fuzzy or not at all.
