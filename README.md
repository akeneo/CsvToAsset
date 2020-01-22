# CsvToAsset

This tool migrates legacy PAM assets (_before 4.0_) to new Asset Manager assets (_available since 3.2_).

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

You need to copy the [.env](https://symfony.com/doc/current/components/dotenv.html) file:
```bash
cp .env .env.local
```

Then open `.env.local` to define the needed configuration vars:
- `AKENEO_API_BASE` refers to the URL of your PIM Enterprise Edition, used for API calls.
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
Find your Enterprise Edition network in this list, stop your Docker, then removes it, to recreate the new one.
```bash
docker-container stop
docker network create pim
docker-container up -d
```

Then, you have to use the `docker-compose.override.yml` of this repository. 
Copy paste the `docker-compose.override.yml.dist` into `docker-compose.override.yml`.
Then, update `/path/to/ee/` to match to your local installation (e.g. `/home/akeneo/pim-ee/`).
Finally, restart your CsvToAsset docker:
```bash
docker-compose up
```

## How to Use

Please read the different scenarios to find the one which will match your needs.

### Full-automatic migration

This mode is for a PIM with a simple usage of the former PAM assets.
It will automatically create your PAM assets and import them it into one unique new Asset family.
As the categories and tags do not exist in the new Asset feature, we will keep them in a specific field on each Asset.

To migrate your data, run
```bash
./bin/migrate.php <family-code> <path-to-ee-installation>
```
with
- `family-code` will be the code of your brand new Asset family,
- `path-to-ee-installation` is the path of your local Enterprise Edition. If you use Docker, this value will be `/srv/ee`.

### Migration semi-automatic

This mode is when you need to create several Asset families.

#### Export assets

You first need to export your assets of your Enterprise Edition. Go to your Enterprise Edition path, then 
```bash
php bin/console pimee:migrate-pam-assets:export-assets <temporary-folder>
```
This command will export 2 files, named `<temporary-folder>/assets.csv` and `<temporary-folder>/variations.csv`, each of them containing the assets and the variations.

#### Choose your families

Open the `assets.csv` file with your favorite spreadsheet editor, to add a new column named `family`.
This column has to be the family code where you want to put your assets in.
You can put a different value at each line of this file, it will create as much families as there are family codes.
Save your `assets.csv` in the same format than the original one (most important is the separator has to be `;`!).

#### Save your connection

Create your new connection to be able to use the API. 
Go to your Enterprise Edition path, then 
```bash
php bin/console akeneo:connectivity-connection:create migrations_pam
```
Store these crentials into a `credentials` file containing 4 lines: clientId, secret, username and password.
Don't forget to remove this file when you finish to import all your assets.

#### Import your assets

Go to CsvToAsset folder and run the migration process:
```bash
php bin/console app:migrate /path/to/assets.csv /path/to/variations.csv
```

#### Update the former PIM attributes

Finally, we need to update the PIM attributes to match the new Asset type.
Go to your Enterprise Edition folder, then run
```bash
php bin/console pimee:assets:migrate:migrate-pam-attributes 
```

### Manual migration

This mode is when you need to create several Asset families and choose every parameter of your families.

#### Export assets

Please refer the "Migration semi-automatic: Export assets" section.

#### Split your assets file

Once you have your `assets.csv` file containing all your assets, you will have to split it into several files.
Each file will contain the assets of a unique family.
Please keep on each file the header of the original `assets.csv`, and keep the same CSV format.
Once done, you should have several files like `assets_packshot.csv`, `assets_designers.csv`,...

#### Save your connection

Please refer the "Migration semi-automatic: Save your connection" section.

#### Import your assets

Now, you will have to migrate your assets family per family.
For each file you created, run this command:
```bash
php bin/console app:migrate /path/to/assets_yourfamily.csv /path/to/variations.csv --asset-family-code=your-family-code
```
The migrate command have a lot of parameters for customize your new Asset family.
Use `php bin/console app:migrate -h` or go the the "Commands" section below.

#### Update the former PIM attributes

Please refer the "Migration semi-automatic: Update the former PIM attributes" section.

## Commands

### `app:migrate [options] <assets-csv-filename> <variations-csv-filename>`
> This command migrates a single assets CSV file to one or several Asset families.

### `app:create-family [options] <asset-family-code>`
> This command creates (using the API) an Asset Family with attributes needed to support legacy PAM structure
- `reference` (_media_file attribute_)
- `reference_localizable` (_media_file attribute_)
- `variation_scopable` (_media_file attribute_)
- `variation_localizable_scopable` (_media_file attribute_)
- `description` (_text attribute_)
- `categories` (_text attribute_)
- `tags` (_text attribute_)
- `end_of_use` (_text attribute_)

Ex: `php bin/console app:create-family my_asset_family`

### `app:merge-files [options] <assets-file-path> <variations-file-path> <target-file-path>`
> This command merges one assets CSV file (`assets-file-path`) and a variations CSV file (`variations-file-path`) into one single file (`target-file-path`), ready to be imported by another command.

**Note regarding memory:** This command loads some values in memory and thus can result in a `PHP Fatal error: Out of memory`.
This can happen if you have:
- A lot of **localizable assets and a lot of locales**
- A **lot variations** (more than 1 million)

If you encounter this problem, we encourage you to increase memory limit when running this command by adding `-d memory_limit=4G` for example.

Ex: `php bin/console app:merge-files var/assets.csv var/variations.csv var/new_assets.csv`

### `app:import [file-path] [asset-family-code]`
> This command imports in the given Asset Family, a given Asset Manager CSV files into the PIM using the API. The `file-path` file should be a file generated by the `merge-files` command.

Ex: `php bin/console app:import var/new_assets.csv my_asset_family`
