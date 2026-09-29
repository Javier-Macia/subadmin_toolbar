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

## Requirements

- Drupal 10, 11 or 12.

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
- **General Settings & Appearance:** `/admin/config/user-interface/subadmin-toolbar/general`
  - Set toolbar brand title, logo/icon, accent color, and enable/disable Font Awesome.
- **Links & Hierarchy Builder:** `/admin/config/user-interface/subadmin-toolbar/links`
  - Toggle between the **Modern Visual Builder** (interactive drag & drop) and the **Classic Table** view.

## License

- GNU General Public License v2.0 or later (GPL-2.0-or-later).
