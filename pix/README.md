# Icons

* `monologo.svg` and `monologo.png` — the single-colour icon. Moodle colours it by plugin purpose,
  but only when a monologo exists; without it the icon counts as branded and stays uncoloured.
* `pluginlogo.svg` and `pluginlogo.png` — the full-colour logo for the plugins directory.

Activity modules additionally declare their purpose in `lib.php`
(`FEATURE_MOD_PURPOSE => MOD_PURPOSE_...`), which decides the icon colour. A purpose of
`MOD_PURPOSE_OTHER` has no colour assigned at all and renders plain.
