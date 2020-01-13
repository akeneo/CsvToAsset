# CsvToAsset

Migrate data from deprecated Akeneo Pim Asset Manager (< 4.0) to the new feature Assets (>= 4.0).

# Installation

```bash
git clone git@github.com:akeneo/CsvToRefenceEntity.git csv_to_reference_entity
cd csv_to_reference_entity
composer install
```

# Setup

You need to copy the [.env](https://symfony.com/doc/current/components/dotenv.html) file:
```bash
cp .env .env.local
```

Then open `.env.local` to define the needed configuration vars:
```
AKENEO_API_BASE_URI=http://your-akeneo-pim-instance.com
```

## Docker setup [optionall]

- Update your Enterprise Edition docker-compose file to allowe external data from network

```yaml
networks:
  pim:
    external: true
```

- Copy paste this `docker-compose.override.yml.dist` into `docker-compose.override.yml` and set your path to Enterprise Edition
- Set into `.env.local` variable `AKENEO_API_BASE_URI=http://httpd:80`

# How to Use

Run `./bin/migrate.php <family-code> <path-to-ee-installation>`

Note: Using docker, your `path-to-ee-installation` will be `/srv/ee`.

This command will
- Export the former Akeneo PAM tables and put it into temporary folder in CSV format
- Create a dedicated API credentials
- Migrate the data:
  - Create a new Asset family with code `family-code`
  - Merge exported Akeneo PAM CSV files
  - Import the merged files into Akeneo PIM in the new Asset format
- Migrate the former Akeneo PAM attributes into new Asset attributes
