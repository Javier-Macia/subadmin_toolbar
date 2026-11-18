# Subadmin Toolbar

A sleek, responsive, and customizable administration toolbar designed specifically for secondary roles (subadmins, editors, managers) in Drupal 10 & 11.

It provides a clean, distraction-free horizontal top toolbar with nested dropdowns, customized branding, role-based item access, and an intuitive **visual drag-and-drop hierarchy builder**.

## Key Features

- **Visual Drag & Drop Builder:** Easily reorder, nest, and configure links and dropdown categories in real-time.
- **Granular Role Permissions:** Restrict each link or category to specific roles with a single click.
- **Decoupled Icon Packages:**
  - **Google Material Symbols & Icons:** Served out-of-the-box.
  - **Font Awesome 6:** Optional, toggleable from general settings.
  - **Zero External Dependencies / Privacy-First:** Ready to be paired with `native_material` and `native_fontawesome` to serve all fonts locally (100% GDPR compliant).
- **Submodules Included:**
  - `subadmin_toolbar_local_material_icons`: Automatically switches the toolbar from Google Fonts CDN to local fonts if `native_material` is installed.
  - `subadmin_toolbar_local_fontawesome`: Automatically switches the toolbar from Cloudflare CDN to local fonts if `native_fontawesome` is installed.
- **Brand Customization:** Custom logo/icon upload, accent colors, and custom brand link.
- **Link descriptions:** Each link can have a short description, shown as a bubble on hover (as plain text under the link on narrow screens).

  - `subadmin_toolbar_subroles`: if you use the `subroles` module, links and widgets get an "Allowed subroles" setting. An item is visible to users with one of its roles or one of its subroles (a subrole only counts while the user has its parent role). If an item has subroles and no roles, only the users with those subroles see it.

## Requirements

- Drupal 10.3+, 11 or 12.

## Installation

Using Composer:

```bash
composer require javierms/subadmin_toolbar
drush en subadmin_toolbar -y
```

To serve icon fonts 100% locally from your server without CDNs:

```bash
composer require javierms/native_material
drush en subadmin_toolbar_local_material_icons -y
```

## Configuration

Navigate to:
- **General Settings & Appearance:** `/admin/config/user-interface/subadmin-toolbar`
  - Set toolbar brand title, logo/icon, accent color, and enable/disable Font Awesome.
- **Links & Hierarchy Builder:** `/admin/config/user-interface/subadmin-toolbar/links`
  - Drag the handle of a row to reorder it; move sideways while dragging to change its level. Branches can be collapsed, the list can be filtered, and with the keyboard you can focus a handle and use the arrow keys to move or indent a link.
  - Each row has its own icon, URL and role restrictions.
- **Widgets:** `/admin/config/user-interface/subadmin-toolbar/widgets`
  - Place any block as a widget on the right end of the toolbar.

Finally, grant the **Access the Subadmin Toolbar** permission to the roles that should see it.

Link URLs must start with `/` (internal path) or be a valid external URL; unsafe protocols such as `javascript:` are rejected.

## Separators

Besides links, the links list can hold separators: a line, with an optional group title, that you place wherever you want it. Use "Add separator" next to "Add link", then drag it to the place you want, at the top level or inside a parent. A separator has a title (optional), roles and the enabled switch; it has no URL or icon.

- Expanded sidebar, submenus and hamburger list: a thin line that spans the row, in the toolbar border color. The title, if any, is shown above the line as a small muted heading.
- Top bar: a thin vertical line between two items. The title is not shown there, except inside a submenu or the "More" menu.
- Collapsed rail: only the line.
- A separator is not focusable and keyboard navigation skips it. It is exposed to assistive technology as `role="separator"` and, with a title, named after it.
- A separator is only shown between two visible links of its level. Leading, trailing and consecutive separators (also those left that way by roles or disabled items) are not rendered, and a parent that only has separators is a plain link.
- Existing links need no changes: an item without a type is a link.

## Layout: top bar or sidebar

The **Layout** group of the general settings controls where the toolbar sits.

- **Position:** `top` (default, horizontal bar), `left` or `right` (vertical sidebar). The top position is unchanged by the sidebar options below.
- **Sidebar mode** (left/right only): `fixed` keeps the sidebar always expanded; `collapsible` adds a toggle button, pinned at the bottom of the sidebar, that shrinks it to a 64 px icon rail. The choice is remembered per browser in `localStorage` (`subadminToolbar.sidebar`); if storage is unavailable the toggle still works for the current page.
- **Sidebar width:** width in pixels of the expanded sidebar, published as `--subadmin-toolbar-sidebar-width`.
- **Start the sidebar collapsed:** the state used when the visitor has no stored choice (collapsible mode only).
- **Narrow screens (768 px and below):** every position falls back to the top bar with the hamburger button; no sidebar, no collapse button, no body padding.
- **Submenus:** in an expanded sidebar submenus open as an accordion inside the column. In the rail, top-level items show only their icon (or the initial letter of the title); submenus open as flyouts next to the rail on hover or focus, and a touch tap on a linked parent opens the flyout first and follows the link on the second tap. Widgets stack as a centered column of icon-only controls above the toggle (text labels stay readable by assistive tech) and their dropdown panels open toward the page. A widget that cannot shrink to an icon (arbitrary block markup, forms, long text) can opt out of the rail by carrying the class `subadmin-widget-no-rail`.

### Gin and core toolbar

The sidebar stacks around the administration theme's own bars instead of covering them. With Gin the left sidebar starts after Gin's vertical navigation and below any Gin top bar (the toolbar is offset by the other top bars); the right sidebar sits at the right edge. The body receives padding equal to the sidebar's own width so page content never sits under it.

### Displace API

The toolbar registers with Drupal's displace API: `#subadmin-toolbar` carries `data-offset-top` (top bar and the narrow hamburger bar), `data-offset-left` or `data-offset-right` (sidebar), set from script together with the layout, so `--drupal-displace-offset-*` and anything that reads it (off-canvas dialogs, modals, sticky headers, Gin) move out of its way. The off-canvas dialog opens beside a right sidebar. In Gin's navigation layout the left sidebar's width reaches the page through the displace offset instead of body padding.

### Keyboard in the sidebar

- Expanded sidebar (and the hamburger list on narrow screens): Down/Up move through the visible items, Right expands a submenu or enters it, Left collapses it or goes to the parent, Home/End jump to the first/last sibling, Escape closes.
- Collapsed rail: Down/Up move through the top-level items; the arrow pointing toward the page (Right on the left sidebar, Left on the right sidebar) enters the flyout, the opposite arrow or Escape returns to the parent item.
- The collapse button is a normal button (Enter or Space) with `aria-expanded`.
- The top bar keeps its horizontal arrow-key model.

### Hooks for themes

Attributes on `#subadmin-toolbar`: `data-position` (`top`, `left`, `right`), `data-sidebar-mode` (`fixed`, `collapsible`), `data-sidebar-collapsed` (configured start state), `data-layout-mode`. On `<html>`: `data-subadmin-sidebar` (`collapsed` or `expanded`, set early from storage in collapsible mode). The class `is-narrow` marks the hamburger layout.

CSS custom properties on `:root` maintained by the module (pixels): `--subadmin-toolbar-offset` (space taken above the content by the toolbar and other top bars), `--subadmin-toolbar-left` and `--subadmin-toolbar-right` (space taken at each side, including other left bars), `--subadmin-toolbar-pad-left` and `--subadmin-toolbar-pad-right` (only the sidebar's own current width, used for body padding), `--subadmin-toolbar-content-top` (`0px` in sidebar mode). On the toolbar: `--subadmin-toolbar-sidebar-width` and `--subadmin-toolbar-height`. A theme that applies `transform`, `filter` or `backdrop-filter` to `#subadmin-toolbar` changes the containing block of the rail flyouts, which are `position: fixed`. A theme that adds its own fixed regions can read the pad variables to stay clear of the sidebar.

## License

- GNU General Public License v2.0 or later (GPL-2.0-or-later).

## Extending

Other modules can add their own settings to links and widgets and take part in their visibility. See `subadmin_toolbar.api.php` for `hook_subadmin_toolbar_item_configuration_alter()`, `hook_subadmin_toolbar_item_values_alter()` and `hook_subadmin_toolbar_item_visibility_alter()`; `subadmin_toolbar_subroles` is a working example.
