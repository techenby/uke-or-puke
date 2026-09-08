---
paths:
  - 'tests/**'
---

# Tests

## Feature tests use RefreshDatabase — finishing a round writes a Score
`tests/Pest.php` applies `RefreshDatabase` to the whole `Feature` suite. It has to: `Arcade::tick()` saves a `Score` the moment a non-demo round finishes, so any test that plays a round to the end touches the database, including tests that never mention scores.

Assert board order through the rows' a11y labels (`Number 1, 800 points, ukulele`) rather than searching the rendered tree for a bare number — `assertSee` reads a11y labels, and short numbers like `140` also appear in layout values. `assertSeeInOrder` does not exist on `TestableComponent`.
