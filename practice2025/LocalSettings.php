<?php
# This file was automatically generated by the MediaWiki 1.39.11
# installer. If you make manual changes, please keep track in case you
# need to recreate them later.
#
# See docs/Configuration.md for all configurable settings
# and their default values, but don't forget to make changes in _this_
# file, not there.
#
# Further documentation for configuration settings may be found at:
# https://www.mediawiki.org/wiki/Manual:Configuration_settings

# Protect against web entry
if (!defined('MEDIAWIKI')) {
    exit;
}


## Uncomment this to disable output compression
# $wgDisableOutputCompression = true;

$wgSitename = "SupportKnowledgeBase";

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "";

## The protocol and server name to use in fully-qualified URLs
$wgServer = "http://localhost:8080";

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

## The URL paths to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
$wgLogos = [
    '1x' => "$wgResourceBasePath/resources/assets/change-your-logo.svg",
    'icon' => "$wgResourceBasePath/resources/assets/change-your-logo.svg",
];

## UPO means: this is also a user preference option

$wgEnableEmail = true;
$wgEnableUserEmail = true; # UPO

$wgEmergencyContact = "";
$wgPasswordSender = "";

$wgEnotifUserTalk = false; # UPO
$wgEnotifWatchlist = false; # UPO
$wgEmailAuthentication = true;

## Database settings
$wgDBtype = "mysql";
$wgDBserver = "database";
$wgDBname = "mediawiki";
$wgDBuser = "wikiuser";
$wgDBpassword = "wikipass";

# MySQL specific settings
$wgDBprefix = "";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Shared database table
# This has no effect unless $wgSharedDB is also set.
$wgSharedTables[] = "actor";

## Shared memory settings
$wgMainCacheType = CACHE_ACCEL;
$wgMemCachedServers = [];

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = false;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = false;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = true;

# Site language code, should be one of the list in ./includes/languages/data/Names.php
$wgLanguageCode = "en";

# Time zone
$wgLocaltimezone = "UTC";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
#$wgCacheDirectory = "$IP/cache";

$wgSecretKey = "3b3404e62984d1f87ac1ec2562e309ddf2410b1b92405570a320a822ed5f3ac0";

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
$wgUpgradeKey = "268cae80c62cbc48";

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "";
$wgRightsText = "";
$wgRightsIcon = "";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "/usr/bin/diff3";

## Default skin: you can change the default skin. Use the internal symbolic
## names, e.g. 'vector' or 'monobook':
$wgDefaultSkin = "vector";

$wgSupportSystemOpenSearchHost = 'opensearch-node1';
$wgSupportSystemOpenSearchPort = 9200;
$wgSupportSystemOpenSearchIndex = 'solutions';
$wgSupportSystemRedmineAPIKey = 'c177337d75a1da3bb43d67ec9b9bb139b299502f';
$wgSupportSystemRedmineURL = getenv('REDMINE_URL') ?: 'http://redmine:3000';
$wgSupportSystemAIServiceURL = getenv('AI_SERVICE_URL') ?: 'http://ai-service:5000';
$wgSupportSystemUseMock = false;
$wgSupportSystemGraphDataFile = '/var/www/html/extensions/SupportSystem/data/graph_data.json';


$wgCirrusSearchIndexBaseName = 'mediawiki';
$wgCirrusSearchUseElasticaSerializer = 5;
$wgCirrusSearchClientSideSearchTimeout = [
    'default' => 60,
];
$wgSearchType = 'CirrusSearch';
$wgCirrusSearchServers = [
    [
        'host' => 'opensearch-node1',
        'port' => 9200,
        'transport' => 'Http',
        'path' => '',
        'schema' => 'http'
    ]
];

// Отключение SSL для OpenSearch
$wgCirrusSearchUseSSL = false;
$wgCirrusSearchUseOpenSearch = true;
// Включение патчей для совместимости с OpenSearch
// require_once "$IP/extensions/Elastica/opensearch-patch.php";
// require_once "$IP/extensions/CirrusSearch/opensearch-patch.php";
$wgHooks['CirrusSearchAlterQueryBuilder'][] = 'MediaWiki\Extension\SupportSystem\CirrusSearchAIHook::onCirrusSearchAlterQueryBuilder';
$wgHooks['CirrusSearchResults'][] = 'MediaWiki\Extension\SupportSystem\CirrusSearchAIHook::onCirrusSearchResults';
$wgAPIModules['unifiedsearch'] = 'MediaWiki\Extension\SupportSystem\API\ApiUnifiedSearch';
$wgSupportSystemAIServiceURL = 'http://ai-service:5000';


# Enabled skins.
# The following skins were automatically enabled:
wfLoadSkin('MinervaNeue');
wfLoadSkin('MonoBook');
wfLoadSkin('Timeless');
wfLoadSkin('Vector');


# End of automatically generated settings.
# Add more configuration options below.

# Load the SupportSystem extension
wfLoadExtension('SupportSystem');
wfLoadExtension('Elastica');
wfLoadExtension('CirrusSearch');
wfLoadExtension('SyntaxHighlight_GeSHi');
