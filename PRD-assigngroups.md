# PRD: Assign Groups

## Purpose

Allow admins to assign each registered child to exactly one group of fixed max size (default 8), with editable group names and a drag-and-drop UX. Children can be moved between groups; assignments and group names are persisted to the database via a Save button.

## Users

- **Admin only.** Same authentication as existing admin pages (session-based, require_admin()).

## Configuration

- **Group max size**: Default 8 children per group. Configurable via the `settings` table key `groups_max_children` (value is a string, e.g. `"8"`). Can be exposed later on the Settings page or kept as a setting read by assigngroups.php.
- **Number of groups**: Hard-coded initially but configurable (e.g. via a setting `groups_count` or derived as `ceil(total_paid_kids / groups_max_children)`). Placeholder groups (“Group 1”, “Group 2”, …) are created when needed.

## Data Model

- **Groups**
  - Stored in table `groups`.
  - Columns: `id` (PK), `name` (varchar, display name editable in UI), `sort_order` (smallint, for ordering in the right panel).
- **Assignments**
  - Each child belongs to at most one group.
  - Stored as nullable `group_id` on `registration_kids` (FK to `groups.id` ON DELETE SET NULL). `group_id = NULL` means unassigned.

## UI

- **Split layout**
  - **Left panel**: List of children from paid registrations only. Each item shows child name (and optionally parent/registration). Each child is draggable.
  - **Right panel**: List of groups. Each group has an editable name (input) and a drop zone showing the children currently in that group. Children can be dragged from the left or from another group into any group.
- **Drag and drop**
  - Children can be dragged from the left panel or from one group and dropped into any group. An “Unassigned” area may be shown (e.g. left panel or a dedicated zone) for kids with no group.
- **Save button**
  - On click, the current assignments and group names are sent to the server and persisted. No auto-save of assignments; group names are saved together with the Save action. Success/error feedback after save.

## Scope

- Only kids from registrations with `status = 'paid'` are shown and assignable.
- Number of groups is hard-coded initially but made configurable (e.g. via settings or constant).

## Out of scope (v1)

- Add/remove groups via UI (optional stretch: “Add group” / “Remove group” buttons).
- Reordering of children within a group is optional.
