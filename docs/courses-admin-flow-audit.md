# Courses Admin Flow Audit

Source reviewed: `\\DESKTOP-M3JKO74\work\upload changes\school\uploagd.zip`

Audit scope: the `Courses` section shown in the admin sidebar, limited to:
- `All Courses`
- `Categories`
- `Program Categories`
- `Educational Program`

Method:
- Code-confirmed review of sidebar, routes, controllers, models, and views inside the archive
- Learner-facing flow traced through `routes/web.php`, `app/Http/Controllers/WebController.php`, and `resources/views/web/*.blade.php`
- No runtime/browser claims unless they are directly supported by code

## Current-State Flow Narrative

The `Courses` menu is presented as one coherent admin area, but the code implements two separate product concepts under the same heading:

1. `All Courses` is an admin list of instructor-authored `Course` records.
2. `Educational Program` is a separate admin-managed catalog built on `EducationalProgram`, with its own categories, public listing, detail page, and enrollment flow.

This means the system currently has:
- an internal `Course` entity used for instructor-created records and admin monitoring
- a public-facing `EducationalProgram` entity used for the website catalog and student enrollment
- a legacy `Category` entity that appears to belong to student classification, not course taxonomy
- a dedicated `EducationalProgramCategory` entity that actually powers the public catalog

From an admin UX perspective, the menu suggests a single hierarchy:
- Courses
- Categories
- Program Categories
- Educational Program

But the real ownership is split:
- `CourseManageController` owns `All Courses`
- `CategoryController` owns `Categories`
- `EducationalProgramCategoryController` owns `Program Categories`
- `WebSettingController` owns `Educational Program`
- `WebController` exposes the public `courses` pages, but those pages use `EducationalProgram`, not `Course`

The result is a menu that looks unified but is logically fragmented.

## Flow Map

### Sidebar entry point

File: `resources/views/layouts/sidebar.blade.php`

Observed menu wiring:
- `All Courses` -> `route('courses.manage.index')`
- `Categories` -> `route('category.index')`
- `Program Categories` -> `route('program-categories.index')`
- `Educational Program` -> `route('educational.index')`

Visibility rules:
- Entire `Courses` group is visible only to `Super Admin`
- `Program Categories` and `Educational Program` are additionally wrapped in `@can('program-create')`

Implication:
- read access in the menu is partially tied to create permission, which is inconsistent with controller-level read gates

### `All Courses`

Route:
- `GET /courses-manage` -> `CourseManageController@index` -> `courses.manage.index`

Controller behavior:
- Loads `Course::with(['instructor.user', 'category'])->latest()->get()`
- Returns `resources/views/courses_manage/index.blade.php`

View behavior:
- Read-only list/dashboard
- Messaging explicitly says courses are created by instructors
- Includes category and publication status summaries

Model path:
- `Course`
- belongs to `Teacher` through `instructor_id`
- belongs to `Category`

Learner-facing output:
- Not connected to the public `/courses` catalog
- Public course pages use `EducationalProgram`, not `Course`

### `Categories`

Routes:
- `Route::resource('category', CategoryController::class)`
- `GET category_list` -> `CategoryController@show`

Controller behavior:
- `index` gated by `category-list`
- `store` gated by `category-create`
- `update` gated by `category-edit`
- `destroy` gated by `category-delete`
- Delete is blocked when `Students::where('category_id', $id)->count()` is non-zero

View behavior:
- Full CRUD screen in `resources/views/category/index.blade.php`
- Bootstrap-table listing plus add/edit/delete modals

Model path:
- `Category`

Learner-facing output:
- Used in student registration (`WebController@registrationIndex`)
- No code-confirmed connection to the public course catalog

### `Program Categories`

Routes:
- `GET program-categories` -> `EducationalProgramCategoryController@index`
- `POST program-categories/store`
- `GET program-categories-list`
- `POST program-categories/update`
- `DELETE program-categories/delete/{id}`

Controller behavior:
- `index` gated by `program-list`
- `store` gated by `program-create`
- `update` gated by `program-edit`
- `destroy` also gated by `program-edit`
- Delete is blocked when an `EducationalProgram` exists with matching category slug

View behavior:
- Full CRUD screen in `resources/views/web_settings/program_categories.blade.php`

Model path:
- `EducationalProgramCategory`

Learner-facing output:
- Public catalog filters and homepage category blocks use `EducationalProgramCategory::active()`
- `Educational Program` forms source category options from this model

### `Educational Program`

Routes:
- `GET educational-program` -> `WebSettingController@educational_index`
- `POST educational-program/store`
- `GET educational-program-list`
- `POST educational-program/update`
- `DELETE educational-program/delete/{id}`

Controller behavior:
- `educational_index` gated by `program-list`
- loads active `EducationalProgramCategory` values and teachers
- CRUD methods create and manage `EducationalProgram`

View behavior:
- Full CRUD screen in `resources/views/web_settings/educational_program.blade.php`
- Fields include title, image, teacher, category, price, rating, short description

Model path:
- `EducationalProgram`
- belongs to `Teacher`

Learner-facing output:
- Public listing route `/courses` uses `EducationalProgram::published()->with('teacher.user')`
- Public detail route `/courses/{program}` uses `EducationalProgram`
- Enrollment and unenrollment also use `EducationalProgram`

## Findings

| Severity | Finding | Evidence | Affected Area | Recommended Fix |
|---|---|---|---|---|
| Miswired | `Categories` under `Courses` is not course taxonomy; it is tied to students. | `CategoryController::destroy()` blocks deletion when `Students::where('category_id', $id)` exists, and `WebController@registrationIndex` loads `Category` for registration. No public catalog path uses `Category`. | Admin IA, Courses menu | Move `Categories` out of `Courses` into a student/admissions area, or rename it explicitly if it must stay. |
| Miswired | `Educational Program` is grouped under `Courses` but owned by `WebSettingController`, which is primarily web/content settings. | Admin route points to `WebSettingController@educational_index`; related views live in `resources/views/web_settings/*`. | Ownership boundaries, maintainability, admin mental model | Move program CRUD into a dedicated `EducationalProgramController` or a `Catalog` module. Keep web settings separate from product data. |
| Confusing UX | The public `/courses` pages do not use `Course`; they use `EducationalProgram`. | `routes/web.php` maps `/courses` to `WebController@courses`, which queries `EducationalProgram::published()`. `courseDetail`, `enrollProgram`, and `unenrollProgram` also use `EducationalProgram`. | Admin menu naming, product comprehension | Rename one of the entities or separate them clearly in the UI: for example `Instructor Courses` vs `Website Programs`. |
| Incomplete | `All Courses` is effectively a monitoring page, not a complete management flow. | `CourseManageController@index` only lists records; no create/edit/delete flow was found in this menu path. The view copy says courses are created by instructors. | Admin flow completeness | Either present it as a read-only oversight page with clearer wording, or add links/actions to the real authoring workflow if admins are expected to manage courses here. |
| Broken | Sidebar visibility for `Program Categories` and `Educational Program` depends on `program-create`, while controller read access depends on `program-list`. | Sidebar uses `@can('program-create')`; `EducationalProgramCategoryController@index` and `WebSettingController@educational_index` require `program-list`. | Permissions, discoverability | Change menu visibility to `program-list` for read pages. Reserve `program-create` for create actions/buttons. |
| Broken | Program category deletion checks the wrong permission. | `EducationalProgramCategoryController::destroy()` checks `program-edit` instead of `program-delete`. | Authorization | Gate delete with `program-delete` to match the action and reduce privilege leakage. |
| Confusing UX | The menu mixes two taxonomies that sound similar but serve different domains. | `Categories` maps to `Category`; `Program Categories` maps to `EducationalProgramCategory`; only the latter feeds public course browsing. | Naming, onboarding, admin comprehension | Use distinct language such as `Student Categories` and `Program Categories`, or remove the unrelated one from this section. |
| Confusing UX | `Educational Program` likely belongs to catalog or website content, not under the same section as instructor course operations. | Program CRUD lives in `web_settings` views and powers the public marketing/enrollment pages. | Information architecture | Group it under `Website`, `Catalog`, or `Programs`, depending on product language. |
| Incomplete | The relation between `Course.category` and `Category` exists in admin code, but there is no code-confirmed learner-facing route consuming `Course` categories. | `CourseManageController` loads `course.category`; public `/courses` does not query `Course` at all. | Data model coherence | Decide whether `Course` is still a live product surface. If yes, add its own public flow. If no, demote or retire it from the main navigation. |
| Confusing UX | The `All Courses` empty state suggests the feature may be partially scaffolded rather than operationally complete. | `resources/views/courses_manage/index.blade.php` states courses will appear “once the courses table is set up,” which reads like unfinished product copy. | Admin trust, product polish | Replace with production-facing guidance that explains where courses come from and what the admin can do here. |

## Per-Menu Audit

### 1. `All Courses`

Purpose implied by UI:
- Central place to manage all courses

Actual implemented behavior:
- Read-only admin listing of `Course` records created by instructors

Permission/role gates:
- Sidebar visibility: `Super Admin`
- Controller: `auth` only in the extracted code, no explicit fine-grained permission gate was confirmed

CRUD coverage:
- List: code-confirmed
- Create: not found in this flow
- Edit: not found in this flow
- Delete: not found in this flow

Dependencies:
- `Course`
- `Teacher`
- `Category`

Overlap assessment:
- Strong overlap with `Educational Program` in naming only
- Distinct entity underneath, but the UI does not explain that distinction

Downstream coherence:
- Weak
- No code-confirmed path from `All Courses` to the public `/courses` pages

Audit verdict:
- `Incomplete` and `Confusing UX`

### 2. `Categories`

Purpose implied by UI:
- Course categories

Actual implemented behavior:
- Category CRUD tied to student records and registration data

Permission/role gates:
- `category-list`
- `category-create`
- `category-edit`
- `category-delete`

CRUD coverage:
- List/create/edit/delete all code-confirmed

Dependencies:
- `Category`
- `Students`

Overlap assessment:
- Overlaps linguistically with `Program Categories`
- Functionally belongs to a different domain

Downstream coherence:
- Coherent for student/admission classification
- Not coherent as part of the course catalog

Audit verdict:
- `Miswired`

### 3. `Program Categories`

Purpose implied by UI:
- Category taxonomy for programs/courses

Actual implemented behavior:
- Correct taxonomy layer for `EducationalProgram`

Permission/role gates:
- Controller index uses `program-list`
- Sidebar uses `program-create`

CRUD coverage:
- Full CRUD present
- Delete permission is incorrectly checked as `program-edit`

Dependencies:
- `EducationalProgramCategory`
- `EducationalProgram`

Overlap assessment:
- Clear relationship to `Educational Program`
- Does not overlap meaningfully with `Category` except by name similarity

Downstream coherence:
- Strong
- Directly powers the public catalog filters and program form options

Audit verdict:
- `Mostly coherent`, but permission wiring is `Broken`

### 4. `Educational Program`

Purpose implied by UI:
- Admin management of course-like offerings

Actual implemented behavior:
- Full CRUD of `EducationalProgram`, which is the entity actually used by the public `/courses` site and student enrollment

Permission/role gates:
- Controller uses `program-list`, `program-create`, `program-edit`, `program-delete`
- Sidebar visibility uses `program-create`

CRUD coverage:
- Full CRUD code-confirmed

Dependencies:
- `EducationalProgram`
- `EducationalProgramCategory`
- `Teacher`

Overlap assessment:
- Conceptually overlaps with `All Courses` from an admin’s perspective because both describe learning offerings
- Technically separate and better connected to the website than `All Courses`

Downstream coherence:
- Strong
- This is the actual public catalog system

Audit verdict:
- `Misgrouped` in IA, but internally coherent

## Why These Four Items Exist Today

The code suggests these menu items were assembled from different product threads over time:

- `All Courses` appears to come from an instructor-led learning flow
- `Categories` appears to come from the older school/student data model
- `Program Categories` and `Educational Program` appear to come from a newer website catalog and enrollment flow

So the menu is not one designed subsystem. It is a composite of:
- school administration taxonomy
- instructor course oversight
- website program catalog management

That explains why it feels illogical from the UI even though individual pages mostly work.

## Logic and Cohesion Assessment

### Does the split between `Categories` and `Program Categories` make product sense?

Not in the current IA.

It only makes sense if admins already understand:
- `Categories` = student/admission classification
- `Program Categories` = website program taxonomy

The current labels do not communicate that difference, so the split reads like duplication.

### Does `Educational Program` belong under `Courses`?

Partially, but not under the current mixed grouping.

If the product’s public catalog is branded as “Courses,” then `Educational Program` is the real course catalog and belongs in a catalog/programs section. What does not fit is placing it beside:
- a student category manager
- an instructor oversight screen that does not feed the public catalog

### Is the current flow logically explainable to an admin?

Not without prior tribal knowledge.

An admin clicking `Courses` would reasonably expect:
- one shared course entity
- one category system
- one clear learner-facing output

The current code delivers:
- two separate learning entities
- two unrelated category systems
- one public catalog driven only by `EducationalProgram`

## Recommended Target Information Architecture

### Option A: keep both entities, but separate them clearly

Recommended menu structure:

- `Learning`
- `Instructor Courses`
- `All Courses`

- `Website Catalog`
- `Programs`
- `Program Categories`
- `Educational Programs`

- `Admissions`
- `Student Categories`

Why this is the safest path:
- minimal conceptual rewrite
- aligns with actual code boundaries
- reduces admin confusion immediately

### Option B: unify on one catalog entity

If the long-term goal is one learning product, choose either `Course` or `EducationalProgram` as the canonical entity and retire the other from navigation. This is a larger product and migration decision, not a quick IA fix.

## Prioritized Remediation List

1. Remove `Categories` from the `Courses` menu or rename it to `Student Categories`.
2. Fix sidebar permissions so `Program Categories` and `Educational Program` are visible with `program-list`, not `program-create`.
3. Fix `EducationalProgramCategoryController::destroy()` to require `program-delete`.
4. Rename admin labels to distinguish the two learning entities: `All Courses` vs `Educational Programs`.
5. Move `Educational Program` and `Program Categories` out of `WebSettingController` into a dedicated controller/module.
6. Clarify the `All Courses` page as either read-only oversight or a true management area with actions and cross-links.
7. Decide product direction for `Course` versus `EducationalProgram` and document which one owns the learner-facing catalog.

## Verification Summary

Code-confirmed:
- Sidebar links exist for all four menu items
- Each menu item has a route and reachable controller action
- `Categories`, `Program Categories`, and `Educational Program` each have rendered list/create/edit flows
- `All Courses` is list-only
- `EducationalProgramCategory` feeds `Educational Program`
- Public `/courses` pages use `EducationalProgram`, not `Course`

Runtime-unverified:
- Whether every AJAX table endpoint renders correctly in-browser
- Whether hidden permission mismatches cause live menu dead-ends for specific non-admin role combinations
- Whether any additional external authoring flow exists for `Course` outside the files reviewed here

## Bottom Line

The pages are not random, but they are not a coherent “Courses” subsystem either.

The main UX issue is not that every menu item is broken. It is that the menu combines three different concepts under one label:
- instructor-created courses
- student categories
- public-facing educational programs

The strongest short-term fix is information architecture and permission cleanup. The strongest long-term fix is choosing whether `Course` and `EducationalProgram` should remain separate products or be unified.
