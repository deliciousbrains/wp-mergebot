=== Mergebot ===
Contributors: deliciousbrains
Tags: data, database, merging, mysql, migration, development, merge, wordpress database merging, merging solution
Requires at least: 4.0
Tested up to: 4.9.4
Requires PHP: 5.3.0
Stable tag: 1.1.6
License: GPLv3

WordPress database merging made easy

== Description ==

Mergebot is the complete solution for merging your databases when developing with different environments for a site.

> <strong>Mergebot App</strong><br>
> The Mergebot plugin integrates with the Mergebot application. You must have a Mergebot account in order to take advantage of this plugin. <a href="https://mergebot.com/?utm_source=wordpress.org&utm_medium=web&utm_campaign=wp-plugin-readme" rel="friend" title="Mergebot App">Click here to create your account</a>.

Mergebot allows you to make changes to a local copy of your site, then apply those changes to the live site without losing any changes that have be made on the live site in that time, effectively merging your databases.

For example, you manage an ecommerce site for a client. If you take a copy of the site and add some pages, you cannot simply migrate the local database to live as it would overwrite any orders that have come in on the live site. With Mergebot, those changes are recorded locally and then safely applied to the live site without interfering with any new live data.

Mergebot safely handles relationships between WordPress data as well as detecting conflicts with changes made on the live site, allowing you to resolve them before applying the changes.

For more information check out <a href="https://mergebot.com/?utm_source=wordpress.org&utm_medium=web&utm_campaign=wp-plugin-readme" rel="friend" title="Mergebot App">the Mergebot site</a>.

= Requirements =

* Mergebot app account
* PHP version 5.3.0 or greater

== Installation ==

1. Create an account over at <a href="https://mergebot.com/?utm_source=wordpress.org&utm_medium=web&utm_campaign=wp-plugin-readme" rel="friend" title="Mergebot App">mergebot.com</a>
1. Install the plugin on both the local and live sites
1. Follow the instructions for configuration <a href="https://app.mergebot.com/docs#/installation?utm_source=wordpress.org&utm_medium=web&utm_campaign=wp-plugin-readme" rel="friend" title="Mergebot installation doc">here</a>

== Screenshots ==

Coming soon

== Changelog ==

= 1.1.6 - 2018-03-23 =

* Bug fix: Recording is attempted for INSERT queries with multiple values, even if they are ignored queries

= 1.1.5 - 2018-03-20 =

* Bug fix: Semi-colons in SQL query values breaking deployment execution

= 1.1.4 - 2018-02-28 =

* Improvement: Schema data updated when plugins and themes are added or updated

= 1.1.3 - 2018-01-09 =

* Bug fix: Fatal error when API key and mode constants are not defined

= 1.1.2 - 2018-01-04 =

* New: Editors (and greater roles) can record changes. Capability can be filtered with 'mergebot_recording_capability'
* Improvement: Query recording paused when a query can't be sent to the app to stop processing bottlenecks on the app
* Improvement: Filter 'mergebot_max_execution_time' added for time limit of background jobs
* Improvement: More diagnostic data added to the log for easier troubleshooting
* Improvement: Link to troubleshooting doc when there is an error creating the mu-plugin
* Bug fix: Can't apply changeset because changes have been recorded after a pull
* Bug fix: WP Migrate DB Pro option queries not being ignored correctly
* Bug fix: SQL queries with escaped slashes not being ignored correctly
* Bug fix: Settings option not created correctly if value was emptied

= 1.1.1 - 2017-11-28 =

* Bug fix: Repeat requests to update site settings on mergebot.com
* Bug fix: Duplicate database calls to check if tables exist

= 1.1.0 - 2017-11-10 =

* Improvement: Further improvements for solving connection issues to mergebot.com, requiring plugin update
* Improvement: CLI command for manually disconnecting the site from the app
* Bug fix: Incorrect handling of GMT offset for cron schedules

= 1.0.4 - 2017-11-08 =

* Bug fix: Sending queries to app results in timeouts
* Bug fix: Production site dropdown doesn't show after disconnecting the development site via the app until plugin page refreshed

= 1.0.3 - 2017-10-27 =

* Bug fix: Insert to wp_mergebot_queries failed for super long SQL queries

== Changelog ==

= 1.0.2 - 2017-10-25 =

* Bug fix: API connection issue notice not being removed when connection is fixed

= 1.0.1 - 2017-09-06 =

* Improvement: Detection of W3 Total Cache db.php incompatibility with notice
* Improvement: Diagnostic log data improvements
* Bug fix: $wpdb instance parent class detection only at one level of inheritance
* Bug fix: Queries executed by a user added to the changeset when recording has been turned on by another user
* Bug fix: Connected Development site incorrect after disconnecting Production site
* Bug fix: Fatal error: Uncaught exception 'ReflectionException' for classes without constructors on some installations

= 1.0.0 - 2017-06-22 =

* Initial public release