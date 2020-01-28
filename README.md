# CsvToAsset

This tool migrates legacy PAM assets (_before 4.0_) to new Asset Manager assets.
Before using this tool, please read the [migration guide](https://help.akeneo.com/pim/serenity/articles/pam-migration-guide.html).

## Installation

If your PIM Enterprise Edition uses Docker, we encourage you to use this tool with Docker too.
First, clone this repository

```bash
git clone git@github.com:akeneo/CsvToAsset.git csv_to_asset
cd csv_to_asset
```

Without Docker, you need to install `composer` locally:
```bash
composer install
```

With Docker, install dependencies with `composer` image:
```bash
docker-compose up
docker run --rm --interactive --tty --volume $PWD:/app --volume ${HOST_COMPOSER_HOME:-~/.composer}:/var/www/.composer composer:1.7 install
```

You're ready to migrate your assets!

## Setup

You need to copy the [.env.dist](https://symfony.com/doc/current/components/dotenv.html) file:
```bash
cp .env.dist .env
```

Then open `.env` to define the needed configuration vars:
- `AKENEO_API_BASE_URI` refers to the URL of your PIM Enterprise Edition, used for API calls.
   For example, `http://localhost:80`.
   If you use Docker, set this value to `http://httpd:80` (or `https://httpd:443` if you use SSL).
- `APP_ENV` refers to the `APP_ENV` of your PIM Enterprise Edition, used for direct bask calls.
   Set it to `prod`, `prod_onprem_paas`...

## Docker setup

First, you have to update your Enterprise Edition `docker-compose.yml` file to allow external data from network.

```yaml
networks:
  pim:
    external: true
```
Then you will have to recreate this network manually. 
Go to your Enterprise Edition directory.
List the current networks with
```bash
docker network list
```
Find your Enterprise Edition network in this list, stop your Docker so it removes it, to be able to create the new one.
```bash
docker-compose stop
docker network create pim
docker-compose up -d
```

Then, you have to use the `docker-compose.override.yml` of this repository. 
Copy paste the `docker-compose.override.yml.dist` into `docker-compose.override.yml`.
Then, update `/path/to/ee/` to match to your local installation (e.g. `/home/akeneo/pim-ee/`).
Finally, restart your CsvToAsset docker:
```bash
docker-compose up
```

## How to Use

Please read the different migration scenarios to find the one which will match your needs.
You can refer to the [migration guide](https://help.akeneo.com/pim/serenity/articles/pam-migration-guide.html) to have a full description of each scenario.

### Full-automatic migration

This mode is for a PIM with a simple usage of the former PAM assets.
It will automatically create your PAM assets and import them into a unique Asset family.
As the categories and tags do not exist in the new Asset feature, we will keep them in a specific field on each Asset.

Please read the [full documentation](https://help.akeneo.com/pim/serenity/articles/full-automatic-pam-migration.html).

### Family-by-family migration

In this mode, you will create several Asset families.

Please read the [full documentation](https://help.akeneo.com/pim/serenity/articles/family-by-family-pam-migration.html).

### Full manual migration

This mode is for users who need to create several Asset families and choose every parameter of your families.

Please read the [full documentation](https://help.akeneo.com/pim/serenity/articles/full-manual-pam-migration.html).

## Commands

### `app:migrate [options] <pim_path> <assets_csv_filename> <variations_csv_filename>`
> This command migrates a single assets CSV file to one or several Asset families.

You can run `app:migrate -h` to have full details about this command's arguments.

You can use a mapping file to rename the default asset family attributes. All the attributes you can map are available in `mapping.json.dist`.
You can, for example, create the above file `mapping.json` and call the migrate command with `--mapping=mapping.json`:
```yaml
{
    "reference": "main_image",
    "variation_scopable": "my_variations",
    "categories": "former_categories"
}
```
It will create a family and import your assets with attributes `main_image`, `my_variations` and `former_categories` instead of the default `reference`, `variation_scopable` and `categories`.

Options:
- `--asset_family_code=ASSET-FAMILY-CODE`
- `--reference_type=localizable|non-localizable|both|auto`
- `--with_categories=yes|no|auto`
- `--with_variations=yes|no`
- `--with_end_of_use=yes|no|auto`
- `--convert_category_to_option=yes|no|auto`
- `--convert_tag_to_option=yes|no|auto`
- `--mapping=MAPPING`

### `app:create-family [options] <asset_family_code>`
> This command creates (using the API) an Asset Family with attributes needed to support the legacy PAM structure
- `reference` (_media_file attribute_)
- `reference_localizable` (_media_file attribute_)
- `variation_scopable` (_media_file attribute_)
- `variation_localizable_scopable` (_media_file attribute_)
- `description` (_text attribute_)
- `categories` (_text attribute_)
- `tags` (_text attribute_)
- `end_of_use` (_text attribute_)

You can run `app:create-family -h` to have full details about this command's arguments.

Options:
- `--reference_type=localizable|non-localizable|both`
- `--with_categories=yes|no`
- `--with_variations=yes|no`
- `--with_end_of_use=yes|no`
- `--category_options=CATEGORY-OPTIONS` (comma separated)
- `--tag_options=TAG-OPTIONS` (comma separated)
- `--mapping=MAPPING`

Ex: `php bin/console app:create-family my_asset_family`

### `app:merge-files [options] <assets_file_path> <variations_file_path> <target_file_path>`
> This command merges one assets CSV file (`assets_file_path`) and a variations CSV file (`variations_file_path`) into one single file (`target_file_path`), ready to be imported by another command.

**Note regarding memory:** This command loads some values in memory and thus can result in a `PHP Fatal error: Out of memory`.
This can happen if you have:
- A lot of **localizable assets and a lot of locales**
- A **lot variations** (more than 1 million)

If you encounter this problem, we encourage you to increase memory limit when running this command by adding `-d memory_limit=4G` for example.

Ex: `php bin/console app:merge-files var/assets.csv var/variations.csv var/new_assets.csv`

You can run `app:merge-files -h` to have full details about this command arguments.

Options:
- `--reference_type=localizable|non-localizable|both`
- `--with_categories=yes|no`
- `--mapping=MAPPING`

### `app:import [file_path] [asset_family_code]`
> This command imports in the given Asset Family, a given Asset Manager CSV files into the PIM using the API. The `file_path` file should be a file generated by the `merge-files` command.

Ex: `php bin/console app:import var/new_assets.csv my_asset_family`
