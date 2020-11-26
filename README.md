# Rename Image Command

This is a WordPress CLI command package for renaming images that have dimensions as their suffix such as `-150x150.jpg`. This can cause some problems in WordPress when parsing content images and determining image sizes.

The original files will be left alone but a new renamed image and thumbnails will be created to avoid any potential broken links.

The command will also optionally perform a search & replace on the database however you should note that this can be very time consuming depending on the size of the database and the number of images to process. This can take roughly 45 seconds per image so you should expect the command to take a few hours if you have 100s of images to rename.

## Installing

This package can be installed as a regular WordPress plugin.

Using Composer:

```
composer require humanmade/rename-images-command
```

As a WP CLI Package:

```
wp package install humanmade/rename-images-command
```

## Usage

```
wp media rename-images
```

### Options

`[--network]` Run the migration for all sites on the network.

`[--sites-page=<int>]` If you have more than 100 sites you can process the next 100 by incrementing this.

`[--search-replace]` Whether to update the database.

`[--tables=<tables>]` A comma separated string of tables to search & replace on. Wildcards are supported. Defaults to `wp_*posts, wp_*postmeta`.

`[--include-columns=<columns>]` The database columns to search & replace on. Defaults to post_content, post_excerpt and meta_value.
