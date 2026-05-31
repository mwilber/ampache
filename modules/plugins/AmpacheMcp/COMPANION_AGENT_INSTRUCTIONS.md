# Companion Agent Instructions

The Ampache MCP server now returns semantic music search results. The agent should use those semantic fields instead of treating the first text row as the only result.

## Search Tool

Call `ampache-search` first when the user asks for music by title, album, artist, or a general phrase.

The tool response includes:

- `content[0].text`: human-readable guidance. The first line states the best interpretation, such as album, song, or artist.
- `structuredContent.bestMatch`: machine-readable best interpretation.
- `structuredContent.albums`: album candidates with `albumId`, `album`, `artist`, `trackCount`, and full `songIds`.
- `structuredContent.songs`: song results.
- `structuredContent.artists`: artist candidates.

The agent must read `structuredContent.bestMatch` when available. Do not assume the first song is the desired target.

## Playlist Tool

Use `ampache-temporary-playlist` to update the persistent playlist named `AI Queue`.

Preferred inputs:

- For an album request, pass `albumId`.
- For one or more songs, pass `songIds`.
- For a high-confidence unresolved query, pass `query`; the MCP server can resolve exact album matches.

Examples:

```json
{
  "albumId": 507,
  "clear": true
}
```

```json
{
  "songIds": [2012, 1975],
  "clear": true
}
```

## Decision Rules

If `bestMatch.type` is `album` and `bestMatch.confidence` is `high`, queue the album using `albumId`.

If `bestMatch.type` is `song` and confidence is `high`, queue the song using `songIds`.

If `bestMatch.type` is `artist`, do not queue every artist song unless the user explicitly asked for a broad artist queue. Prefer asking a follow-up such as whether they want top songs, albums, or a specific album.

If `bestMatch.ambiguous` is `true`, ask a follow-up unless the user's original wording clearly resolves the ambiguity.

## Example

User request:

```text
Play Yield
```

Expected search interpretation:

```json
{
  "type": "album",
  "album": "Yield",
  "artist": "Pearl Jam",
  "confidence": "high"
}
```

Expected playlist call:

```json
{
  "albumId": 507,
  "clear": true
}
```

Do not use only the first song returned from the search result for album-like queries.
