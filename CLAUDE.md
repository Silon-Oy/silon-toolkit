# Silon Toolkit

Silon Oy:n WordPress-sivustojen yhteinen mu-plugin. Asennetaan jokaiselle sivustolle Composerin kautta.

## Arkkitehtuuri

- **Tyyppi:** MU-plugin (mu-plugins-kansio, latautuu automaattisesti ennen tavallisia plugineja)
- **Rakenne:** Yksi PHP-tiedosto per ominaisuus. Juuressa oleva `silon-toolkit.php` (mu-plugins-kansion tasolla) lataa kaikki alikansion PHP-tiedostot `glob()`-kutsulla.
- **Namespace:** `SilonToolkit` (kun luokkia tarvitaan)
- **Ympäristön tunnistus:** `wp_get_environment_type()` — edellyttää `WP_ENVIRONMENT_TYPE`-vakion wp-config.php:ssä

## Konventiot

- Jokainen ominaisuus omassa tiedostossaan — ei monolittiluokkaa
- Tiedoston alussa docblock joka kuvaa mitä ominaisuus tekee
- ABSPATH-tarkistus jokaisen tiedoston alussa
- Ympäristökohtaiset ominaisuudet ehdollisia `wp_get_environment_type()`-kutsun perusteella
- Ei tietokantamuutoksia: käytetään `pre_option_*`-filttereitä option-arvojen ylikirjoittamiseen
- Staattiset luokat ilman instantiointia (`Class::init()`)

## Asennus

Composer-riippuvuutena projektin `composer.json`:ssa:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:Silon-Oy/silon-toolkit.git"
        }
    ],
    "require": {
        "silon-oy/silon-toolkit": "dev-main"
    }
}
```

## Komennot

```bash
composer lint          # Tarkista koodityyli (PHPCS, vain virheet)
composer lint-all      # Tarkista koodityyli (virheet + varoitukset)
composer lint-fix      # Korjaa koodityyli automaattisesti (PHPCBF)
```

## Repo

- **GitHub:** Silon-Oy/silon-toolkit (private)
- **Branch:** main
