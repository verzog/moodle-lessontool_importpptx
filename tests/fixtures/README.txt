sample.pptx — synthetic test fixture for local_lessonimportpptx
===============================================================

This small .pptx is generated (not authored in PowerPoint) so it exercises one
of each supported extraction case in a single, reviewable deck. It is consumed
by tests/importer_test.php and tests/behat/import.feature.

Slides (one lesson content page each, in order):

  1. Title slide            - title placeholder -> page title.
  2. Bullets + image        - two paragraphs -> <ul>, a bold run -> <strong>,
                              one image referenced via @@PLUGINFILE@@.
  3. Three captioned images - short lines "13:00/14:00/15:00" pair as captions
                              above a three-column Bootstrap grid; also proves
                              left-to-right reading order within a row.
  4. SmartArt               - diagram text recovered from drawing1.xml as a list.
  5. Table                  - a:tbl -> <table> with the first row as <th>.
  6. Section divider        - full-height left plate (fill #1F4E79) with the label
                              "SECTION ONE"; detected by geometry and styled as a
                              hero. Lesson has no page hierarchy, so it stays an
                              ordinary page in the chain.
  7. Ordinary follower      - an ordinary content page after the divider.
  8. Decorative badge       - a lone "AT" label (<= 4 chars) is dropped; a run with
                              a line break keeps its <br>.
  9. No-title fallback      - no title placeholder, so the first short line
                              ("Short Heading") is promoted to the page title.

The deck was produced by a small development-only generator script (not shipped
with the plugin) that writes the OOXML parts directly. The bytes are deterministic
apart from the tiny solid-colour PNGs used as placeholder images.
