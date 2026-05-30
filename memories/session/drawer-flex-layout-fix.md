# Session: Drawer Flex Layout Fix (2026-05-30)

## Problem
Student attachment drawer caused `.umat-input-area` to be "pushed down" when drawer was placed inside it with `position:absolute`.

## Root Cause
`.umat-input-area` is a flex item inside a `display:flex;flex-direction:column;` container. Putting a `position:absolute` child inside a flex item may cause some browsers to recalculate the flex item's min-content/max-content size incorrectly, pushing the input area down.

## Fix
1. **Reverted**: Moved `.umat-attach-drawer` back outside `.umat-input-area` (original structure)
2. **Added**: `position:relative` wrapper `<div style="position:relative;">` wrapping BOTH the input area and the drawer
3. **Containing block**: The wrapper has height = input area's height (drawer is absolute, out of flow). `bottom:100%` places drawer bottom at wrapper top (= input area top). `transform:translateY(100%)` pushes it below. `.open` with `translateY(0)` slides it above.
4. **Applied to**: Both student (`ws-attach-drawer`) and hub (`hub-attach-drawer`) overlays

## Files Changed
- `before_footer.php`: Lines 1325-1348 (student) and 2804-2828 (hub)

## Verification
- Drawer should NOT affect input area height (no flex layout interference)
- Drawer opens above input area with slide-up animation
- Drawer closes (slides below, hidden by `umat-ov-content`'s `overflow:hidden`)
