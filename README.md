# Site Health

Add your plugin's checks and its own section to Tools > Site Health, so the
screen people already open when something goes wrong can tell them what is
wrong with your plugin and what is installed.

## What it does

When a site has a problem, WordPress sends people to Site Health. Support asks
them to copy its Info tab. A plugin that builds its own "system status" page
gives them a second place to look and you a second page to maintain.

This puts the plugin on Site Health instead, from arrays. Each check returns a
result. The library adds the plugin's badge (red when the result is critical),
a link to fix the problem when the result isn't good, and the name WordPress
expects. Your Info section gets the version, the PHP and WordPress
requirements, the database tables and the bundled libraries, plus your own
lines. Lines marked private stay out of the copied text.

## Features

* Show a plugin's problems on Site Health with a headline, an explanation and
  a link to fix them
* Return a result in one line: `Result::critical( $headline, $why )`
* Catch a feature that is only half set up, naming the settings it is missing
* Run a slow check after the page loads, over REST, and directly from
  WordPress's weekly cron
* Show or hide a check depending on the site, such as only when a feature is
  turned on
* Add a section to the Info tab: version, requirements, database tables with
  their versions, bundled libraries and the commit each was built from
* Keep email addresses, URLs and anything else that identifies a site out of
  the text the Copy button copies
* Let add-ons add their own checks and lines under the plugin's name

## Installation

```bash
composer require sugarcommerce/site-health
```

## Quick start

```php
use SugarCommerce\SiteHealth\{SiteHealth, Result, Info};

SiteHealth::register( 'myshop', [
	'label'   => __( 'My Shop', 'my-shop' ),
	'actions' => fn() => [
		'url'   => admin_url( 'admin.php?page=myshop-settings' ),
		'label' => __( 'Open My Shop settings', 'my-shop' ),
	],
	'tests'   => [
		'api_key' => function () {
			if ( myshop_get_api_key() ) {
				return Result::good( __( 'My Shop can reach its payment provider', 'my-shop' ) );
			}

			return Result::critical(
				__( 'My Shop has no API key', 'my-shop' ),
				__( 'Checkout cannot take payment. Paste a key into <code>Settings</code>.', 'my-shop' )
			);
		},
	],
	'info'    => [
		'version'  => MYSHOP_VERSION,
		'requires' => [ 'php' => '8.3', 'wp' => '7.1' ],
		'fields'   => fn() => [
			'mode'    => Info::line( __( 'Mode', 'my-shop' ), __( 'Live', 'my-shop' ), 'live' ),
			'api_key' => Info::yes_no( __( 'API key saved', 'my-shop' ), '' !== myshop_get_api_key() ),
		],
	],
] );
```

Call it on `plugins_loaded` or earlier.

## Checks

A check is a callable that returns a result. You can give the callable on its
own, or as `callback` alongside these options:

```php
'tests' => [
	'webhook'   => [ 'callback' => [ Health::class, 'webhook' ], 'label' => __( 'My Shop webhooks', 'my-shop' ) ],
	'turnstile' => [ 'callback' => [ Health::class, 'turnstile' ], 'enabled' => fn() => myshop_turnstile_on() ],
	'reachable' => [ 'callback' => [ Health::class, 'reachable' ], 'async' => true ],
],
```

Each check is registered as `{id}_{check}`, here `myshop_webhook`. `label`
is the text shown while the check runs, and defaults to the plugin's label.
`enabled` is a boolean or a callable. When it's false, the check isn't
listed. `skip_cron` keeps the check out of WordPress's weekly background run.

`async` checks run after the page has loaded, through
`{id}-site-health/v1/tests/{check}`. They use the same permission as
WordPress's own async checks. The weekly cron calls them directly.
`rest_namespace` changes the route.

`$health->run( 'webhook' )` runs one check and returns what the screen would
draw. Tests can use it.

## Results

```php
Result::good( $headline, $why );
Result::recommended( $headline, $why );
Result::critical( $headline, $why );
```

`$why` can be plain text with inline markup such as `<code>`, `<strong>` or
links, and is wrapped in a paragraph. Markup that starts with a paragraph or a
list is used as it is. Anything a description doesn't need is removed.

An optional feature set up by several settings is either all there, off, or
half set up -- the case worth catching, because the feature is offered and
then fails:

```php
return Result::all_or_none(
	__( 'Discord roles', 'my-shop' ),
	[ __( 'Client ID', 'my-shop' ) => $client_id, __( 'Bot token', 'my-shop' ) => $token ],
	__( 'Buyers are given their Discord role', 'my-shop' ),     // headline when all are set
	__( 'Buyers are not given a Discord role.', 'my-shop' )     // what is missed otherwise
);
```

All set is `good`. None set is `recommended`, "Discord roles is switched off",
saying which settings switch it on. Some set is `recommended`, "Discord roles
is only half set up", naming the missing ones. Never `critical`: the plugin
works without it. `''`, `null` and `false` count as not set; `0` is a value.

A result that isn't good gets the plugin's `actions` link. To use something
else, pass a third argument: another `[ 'url' => …, 'label' => … ]`, a list of
them, markup, or `''` for no link. A result can also carry its own `badge`
label or colour. Check callbacks can return Site Health's own array too.
Anything missing is filled in, and an unknown status becomes `recommended`.

## The Info section

The section is keyed by the id and has the plugin's label unless `info`
provides a `label`. Its lines appear in this order:

1. `version` and `requires`, if given
2. your `fields`, an array or a callable
3. `tables`: BerlinDB tables or table names (an iterable, or a callable that
   returns one). They're summarised in one line that names only the missing
   tables, followed by each table's version.
4. `libraries`: the path to Composer's or Strauss's `installed.json`. Each
   library is listed with its version, and a branch also gets the commit it
   was built from.
5. whatever add-ons add through `{id}_site_info`. Rename the filter with
   `'filter' => '…'`, or turn it off with `false`.

`private` and `show_count` on `info` pass through to WordPress. Set
`'info' => false` for no section at all.

### Lines

```php
Info::line( $label, $shown, $copied = null, $hidden = false ); // true: private, left out of the copy
Info::yes_no( __( 'Secret key saved', 'my-shop' ), $saved );      // never the key itself
Info::when( __( 'Last webhook', 'my-shop' ), $row->date_created ); // "3 hours ago" / "2026-11-17 09:30 UTC"
Info::schedule( __( 'Daily clean-up', 'my-shop' ), wp_next_scheduled( 'myshop_clean' ) );
Info::tables( $tables );
Info::libraries( MYSHOP_DIR . 'vendor/composer/installed.json' );
```

Every line has two forms: what the screen shows, in the site's language, and
what the Copy button copies, in plain English, because whoever reads the copy
may not read the site's language. Mark a line private when it identifies the
site, such as an email address or a webhook URL. The screen shows it, and the
copy leaves it out, because the copy ends up in support tickets and public
forums. Never include a key or a password in a line. Use `yes_no()` to say
whether one is set.

## Add-ons

```php
add_filter( 'myshop_site_health_tests', function ( array $tests ): array {
	$tests['payouts'] = fn() => Result::recommended( __( 'Two payouts are waiting', 'my-shop-affiliates' ) );
	return $tests;
} );

add_filter( 'myshop_site_info', function ( array $fields ): array {
	$fields['affiliates'] = Info::line( __( 'Affiliates', 'my-shop-affiliates' ), '12' );
	return $fields;
} );
```

## Elsewhere in the plugin

`SiteHealth::get( 'myshop' )` returns the registration and
`SiteHealth::unregister( 'myshop' )` takes it out. `SiteHealth::info_url()` and
`SiteHealth::status_url()` link to the two tabs.

## Moving from arraypress/wp-*

From `arraypress/wp-register-site-health` (`ArrayPress\RegisterSiteHealth`).
Every class, method, hook and REST route keeps its name; the global function
is gone -- a global function is the one thing Strauss cannot prefix.

| Old | New |
|---|---|
| `ArrayPress\RegisterSiteHealth\SiteHealth` | `SugarCommerce\SiteHealth\SiteHealth` |
| `ArrayPress\RegisterSiteHealth\Result` | `SugarCommerce\SiteHealth\Result` |
| `ArrayPress\RegisterSiteHealth\Info` | `SugarCommerce\SiteHealth\Info` |
| `register_site_health( $id, $config )` | `SiteHealth::register( $id, $config )` |
| `{id}_site_health_tests`, `{id}_site_info`, `{id}-site-health/v1/tests/{check}` | unchanged |
| text domain `arraypress` | text domain `sugarcommerce` |

New: `Result::all_or_none()`, from the retired SaaS `health` package. Its
console report, exit code and chat-webhook alert are left out: on WordPress
the Site Health screen is the report, and WordPress's weekly run is the cron.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
