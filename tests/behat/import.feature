@local @local_lessonimportpptx @_file_upload
Feature: Import a PowerPoint presentation into a lesson
  In order to reuse existing slides as course material
  As a teacher
  I need to import a .pptx and get one editable content page per slide

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "activities" exist:
      | activity | name        | intro             | course | idnumber |
      | lesson   | Test lesson | Lesson for import | C1     | lesson1  |

  @javascript
  Scenario: Importing a deck creates a content page per slide
    When I am on the "lesson1" "local_lessonimportpptx > Import" page logged in as "admin"
    And I upload "local/lessonimportpptx/tests/fixtures/sample.pptx" file to "PowerPoint or PDF file" filemanager
    And I press "Import"
    Then I should see "Create 9 content pages"
    When I press "Continue"
    Then I should see "Imported 9 pages"
    And I should see "Overview"
    And I should see "Getting Started"
