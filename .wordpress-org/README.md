# WordPress.org Assets

This directory contains assets for the WordPress.org plugin page.
These files are uploaded to the `/assets/` directory of the wp.org SVN repo
and are **not** included in the plugin zip.

## Required files

| File                  | Size        | Description                          |
|-----------------------|-------------|--------------------------------------|
| `banner-772x250.png`  | 772 x 250 px | Plugin banner (listing page header) |
| `banner-1544x500.png` | 1544 x 500 px | Retina plugin banner (optional)    |
| `icon-128x128.png`    | 128 x 128 px | Plugin icon (small)                 |
| `icon-256x256.png`    | 256 x 256 px | Plugin icon (retina)                |
| `screenshot-1.png`    | any          | Admin interface — rule list          |
| `screenshot-2.png`    | any          | Checkout warning modal example       |

## SVN structure

```
/assets/          ← these files go here
/trunk/           ← plugin source (from git main branch)
/tags/1.0.0/      ← tagged release copy
```

## Deployment

After wp.org approval, push to SVN:

```bash
# Check out the SVN repo (URL provided by wp.org)
svn co https://plugins.svn.wordpress.org/shipping-destination-notices-for-woocommerce/ svn-repo
cd svn-repo

# Copy plugin files to trunk
rsync -av --exclude='.git' --exclude='.vscode' --exclude='.cursor' \
  --exclude='.wordpress-org' --exclude='.gitignore' --exclude='.DS_Store' \
  /path/to/shipping-destination-notices-for-woocommerce/ trunk/

# Copy assets
cp /path/to/.wordpress-org/*.png assets/

# Tag the release
svn cp trunk tags/1.0.0

# Commit
svn ci -m "Release 1.0.0"
```
