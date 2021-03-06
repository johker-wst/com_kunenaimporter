<?php
/**
 * Kunena Importer component
 * @package Kunena.com_kunenaimporter
 *
 * @copyright (C) 2008 - 2012 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

// Import Joomla! libraries
jimport ( 'joomla.application.component.model' );
jimport ( 'joomla.application.application' );

require_once (JPATH_COMPONENT . '/models/export.php');

/**
 * phpBB3 Exporter Class
 *
 * Exports almost all data from phpBB3.
 * @todo Configuration import needs some work
 * @todo Forum ACL not exported (except for moderators)
 * @todo URL avatars not exported
 * @todo Ranks not exported
 * @todo Private messages not exported
 * @todo Some emoticons may be missing (images/db are not exported)
 */
class KunenaimporterModelExport_phpBB3 extends KunenaimporterModelExport {
	/**
	 * Extension name
	 * @var string
	 */
	public $extname = 'phpbb3';
	/**
	 * Display name
	 * @var string
	 */
	public $exttitle = 'phpBB3';
	/**
	 * External application
	 * @var bool
	 */
	public $external = true;
	/**
	 * Minimum required version
	 * @var string or null
	 */
	protected $versionmin = '3.0.8';
	/**
	 * Maximum accepted version
	 * @var string or null
	 */
	protected $versionmax = '3.0.999';

	protected $rokbridge = null;
	protected $dbconfig = null;

	/**
	 * Get forum path from importer configuration
	 *
	 * @return bool
	 */
	public function getPath($absolute = false) {
		// Load rokBridge configuration (if exists)
		if ($this->rokbridge === null && version_compare(JVERSION, '1.6', '<')) {
			$this->rokbridge = JComponentHelper::getParams( 'com_rokbridge' );
		}
		$path = $this->rokbridge ? $this->rokbridge->get('phpbb3_path') : '';
		if (!$this->params->get('path') && $path) {
			// Get phpBB3 path from rokBridge
			$this->relpath = $path;
			$this->basepath = JPATH_ROOT."/{$this->relpath}";
			return $absolute ? $this->basepath : $this->relpath;
		}
		return parent::getPath($absolute);
	}

	/**
	 * Detect if component and config.php exists
	 *
	 * @return bool
	 */
	public function detectComponent($path=null) {
		if ($path === null) $path = $this->basepath;
		// Make sure that configuration file exist, but check also something else
		if (!JFile::exists("{$path}/config.php")
			|| !JFile::exists("{$path}/adm/swatch.php")
			|| !JFile::exists("{$path}/viewtopic.php")) {
			return false;
		}
		return true;
	}

	/**
	 * Get database object
	 */
	public function getDatabase() {
		$config = $this->getDBConfig();
		$database = null;
		if ($config) {
			$app = JFactory::getApplication ();
			$option ['driver'] = $app->getCfg ( 'dbtype' );
			$option ['host'] = $config['dbhost'];
			$option ['user'] = $config['dbuser'];
			$option ['password'] = $config['dbpasswd'];
			$option ['database'] = $config['dbname'];
			$option ['prefix'] = $config['table_prefix'];
			$database = JDatabase::getInstance ( $option );
		}
		return $database;
	}

	/**
	 * Get database settings
	 */
	protected function &getDBConfig() {
		if (!$this->dbconfig) {
			require "{$this->basepath}/config.php";
			$this->dbconfig = get_defined_vars();
		}
		return $this->dbconfig;
	}

	public function initialize() {
		global $phpbb_root_path, $phpEx;

		if(!defined('IN_PHPBB')) {
			define('IN_PHPBB', true);
		}

		if(!defined('STRIP')) {
			define('STRIP', (get_magic_quotes_gpc()) ? true : false);
		}

		$phpbb_root_path = $this->basepath.'/';
		$phpEx = substr(strrchr(__FILE__, '.'), 1);
	}

	public function &getConfig() {
		if (empty($this->config)) {
			// Check if database settings are correct
			$query = "SELECT config_name, config_value AS value FROM #__config";
			$this->ext_database->setQuery ( $query );
			$this->config = $this->ext_database->loadObjectList ('config_name');
		}
		return $this->config;
	}


	/**
	 * Full detection
	 *
	 * Make sure that everything is OK for full import.
	 * Use $this->addMessage($html) to add status messages.
	 * If you return false, remember also to fill $this->error
	 *
	 * @return bool
	 */
	public function detect() {
		// Initialize detection (also calls $this->detectComponent())
		if (!parent::detect()) return false;

		// Check RokBridge
		if ($this->rokbridge && $this->rokbridge->get('phpbb3_path')) {
			$this->addMessage ( '<div>RokBridge: <b style="color:green">detected</b></div>' );
		}

		// Check authentication method
		$query = "SELECT config_value FROM #__config WHERE config_name='auth_method'";
		$this->ext_database->setQuery ( $query );
		$auth_method = $this->ext_database->loadResult () or die ( "<br />Invalid query:<br />$query<br />" . $this->ext_database->errorMsg () );
		$this->addMessage ( '<div>phpBB authentication method: <b style="color:green">' . $auth_method . '</b></div>' );

		// Find out which field is used as username
		$fields = $this->ext_database->getTableFields('#__users');
		$this->login_field = isset($fields['#__users']['login_name']);
		return true;
	}

	/**
	 * Get component version
	 */
	public function getVersion() {
		$query = "SELECT config_value FROM #__config WHERE config_name='version'";
		$this->ext_database->setQuery ( $query );
		$version = $this->ext_database->loadResult ();
		// phpBB2 version
		if ($version [0] == '.')
			$version = '2' . $version;
		return $version;
	}

	/**
	 * Remove htmlentities, addslashes etc
	 *
	 * @param string $s String
	 */
	protected function parseText(&$s) {
		$s = html_entity_decode ( $s );
	}

	/**
	 * Convert BBCode to Kunena BBCode
	 *
	 * @param string $s String
	 */
	protected function parseBBCode(&$s) {
		$s = html_entity_decode ( $s );

		// [b]: bold font
		$s = preg_replace ( '/\[b(:.*?)\]/', '[b]', $s );
		$s = preg_replace ( '/\[\/b(:.*?)\]/', '[/b]', $s );

		// [i]: italic font
		$s = preg_replace ( '/\[i(:.*?)\]/', '[i]', $s );
		$s = preg_replace ( '/\[\/i(:.*?)\]/', '[/i]', $s );

		// [u]: underlined font
		$s = preg_replace ( '/\[u(:.*?)\]/', '[u]', $s );
		$s = preg_replace ( '/\[\/u(:.*?)\]/', '[/u]', $s );

		// [quote], [quote=*]: (named) quotes
		$s = preg_replace ( '/\[quote(:.*?)\]/', '[quote]', $s );
		$s = preg_replace ( '/\[quote=["\']?([^"\']+?)["\']?(\s*):([^:]+?)\]/', '[quote="\\1"]', $s );
		$s = preg_replace ( '/\[\/quote(:.*?)\]/', '[/quote]', $s );
		
		// [img]: images
		$s = preg_replace ( '/\[img(:.*?)\]/', '[img]', $s );
		$s = preg_replace ( '/\[\/img(:.*?)\]/', '[/img]', $s );
		
		// [color=*]: font color
		$s = preg_replace ( '/\[color=(.+?):([^:]+?)\]/', '[color=\\1]', $s );
		$s = preg_replace ( '/\[\/color(:.*?)\]/', '[/color]', $s );
		
		/*
		 * [size=*]: font size
		 * Sizes range from 1 <= size <= 200. Map them to 1-6 using the
		 * following map:
		 *
		 * 		phpbb		kunena
		 *     1-39			1
		 *     40-79		2
		 *     80-119		3
		 *     120-159		4
		 *     160-179		5
		 *     180-200		6
		 */
		$s = preg_replace ( '/\[size=[123]?[0-9](:.*?)\]/', '[size=1]', $s );
		$s = preg_replace ( '/\[size=[4567][0-9](:.*?)\]/', '[size=2]', $s );
		$s = preg_replace ( '/\[size=(8|9|10|11)[0-9](:.*?)\]/', '[size=3]', $s );
		$s = preg_replace ( '/\[size=(12|13|14|15)[0-9](:.*?)\]/', '[size=4]', $s );
		$s = preg_replace ( '/\[size=(16|17)[0-9](:.*?)\]/', '[size=5]', $s );
		$s = preg_replace ( '/\[size=(((18|19)[0-9])|200)(:.*?)\]/', '[size=6]', $s );
		$s = preg_replace ( '/\[\/size(:.*?)\]/', '[/size]', $s );

		// [code], [code=*]: raw code with and without syntax highlighting
		$s = preg_replace ( '/\[code(:.*?)\]/', '[code]', $s );
		$s = preg_replace ( '/\[code=([a-z]+):([^:]+?)\]/', '[code type="\\1"]', $s );
		$s = preg_replace ( '/\[\/code(:.*?)\]/', '[/code]', $s );

		// [list], [list=*], [*], [/*]: lists (ordered, unordered), list elements
		// default list
		$s = preg_replace ( '/\[list(:.*?)\]/', '[list]', $s );
		// roman
		$s = preg_replace ( '/\[list=([iI])(:.*?)\]/', '[list=\\1]', $s );
		// numeric
		$s = preg_replace ( '/\[list=([0-9]+)(:.*?)\]/', '[list=1]', $s );
		// lower alpha
		$s = preg_replace ( '/\[list=([a-z])(:.*?)\]/', '[list=a]', $s );
		// upper alpha
		$s = preg_replace ( '/\[list=([A-Z])(:.*?)\]/', '[list=A]', $s );
		// misc
		$s = preg_replace ( '/\[list=(disc|circle|square)(:.*?)\]/', '[list=\\1]', $s );

		$s = preg_replace ( '/\[\/list:u(:.*?)\]/', '[/list]', $s );
		$s = preg_replace ( '/\[\/list:o(:.*?)\]/', '[/list]', $s );

		// Kunena seems to cope just fine with missing [/li], so we can thankfully omit inserting missing ones,
		// which would require counting
		$s = preg_replace ( '/\[\*(:.*?)\]/', '[li]', $s );
		$s = preg_replace ( '/\[\/\*(:.*?)\]/', '[/li]', $s );

		// smileys
		$s = preg_replace ( '/<!-- s(.*?) --\>\<img src=\"{SMILIES_PATH}.*?\/\>\<!-- s.*? --\>/', ' \\1 ', $s );

		// misc
		// TODO inline images should still show up where they were originally
		$s = preg_replace ( '/\<!-- e(.*?) --\>/', '', $s );
		$s = preg_replace ( '/\<!-- w(.*?) --\>/', '', $s );
		$s = preg_replace ( '/\<!-- m(.*?) --\>/', '', $s );
		$s = preg_replace ( '/\<!-- l(.*?) --\>/', '', $s ); // local url
		$s = preg_replace ( '/\<!-- ia(.*?) --\>/', '', $s ); // inline attachment

		// [email], [email=*]: mailto link with optional text
		$s = preg_replace ( '/\[email(:.*?)\]/', '[email]', $s );
		$s = preg_replace ( '/\[email=(.+?):([^:]+?)\]/', '[email="\\1"]', $s );
		$s = preg_replace ( '/\[\/email(:.*?)\]/', '[/email]', $s );

		// <a href="*">*</a>: HTML links with optional text
		// TODO: convert urls (they are still in phpbb format, not kunena format)
		$s = preg_replace ( '/\<a class=\"postlink\" href=\"(.*?)\"\>(.*?)\<\/a\>/', '[url="\\1"]\\2[/url]', $s );
		$s = preg_replace ( '/\<a class=\"postlink-local\" href=\"(.*?)\"\>(.*?)\<\/a\>/', '[url="\\1"]\\2[/url]', $s );
		$s = preg_replace ( '/\<a href=\"(.*?)\"\>(.*?)\<\/a\>/', '[url="\\1"]\\2[/url]', $s );

		$s = preg_replace ( '/\<a href=.*?mailto:.*?\>/', '', $s );

		$s = preg_replace ( '/\<\/a\>/', '', $s );

		// [url], [url=*]: URL links with optional text
		$s = preg_replace ( '/\[url(:.*?)\]/', '[url]', $s );
		$s = preg_replace ( '/\[url=(.+?):([^:]+?)\]/', '[url="\\1"]', $s );
		$s = preg_replace ( '/\[\/url(:.*?)\]/', '[/url]', $s );

		// [flash]: flash videos
		$s = preg_replace ( '/\[flash(.*?)\](.*?)\[\/flash(.*?)\]/', '', $s );

		// [s], [strike]: strikethrough font
		$s = preg_replace ( '/\[(s|strike)(:.*?)\]/', '[strike]', $s );
		$s = preg_replace ( '/\[\/(s|strike)(:.*?)\]/', '[/strike]', $s );

		// [blink]: blinking font
		$s = preg_replace ( '/\[blink(:.*?)\]/', '', $s );
		$s = preg_replace ( '/\[\/blink(:.*?)\]/', '', $s );

		// [spoiler]: spoiler (expandable section, default: collapsed)
		$s = preg_replace ( '/\[spoiler(:.*?)\]/', '[spoiler]', $s );
		$s = preg_replace ( '/\[\/spoiler(:.*?)\]/', '[/spoiler]', $s );

		// [youtube]: youtube videos
		// get youtube video id: http://stackoverflow.com/questions/3392993/php-regex-to-get-youtube-video-id
		$s = preg_replace_callback ( '/\[youtube(:.*?)\](.*)\[\/youtube(:.*?)\]/', 
				function($m) {
					$parts = parse_url($m[2]);
					if (isset($parts['query'])) {
						parse_str($parts['query'], $qs);
						if (isset($qs['v'])) {
							$id = $qs['v'];
						} else if($qs['vi']) {
							$id = $qs['vi'];
						}
					}
					if (!isset($id) && isset($parts['path'])) {
						$path = explode('/', trim($parts['path'], '/'));
						$id = $path[count($path)-1];
					}
					
					return '[video type="youtube"]' . $id . '[/video]';
				}
			, $s);
	}

	/**
	 * Map Joomla user to external user
	 *
	 * @param object $joomlauser StdClass(id, username, email)
	 * @return int External user ID
	 */
	public function mapJoomlaUser($joomlauser) {
		if ($this->login_field) {
			// Use login_name created by SMF to phpBB3 convertor
			$field = 'login_name';
			$username = $joomlauser->username;
		} else {
			$field = 'username_clean';
			$username = utf8_clean_string($joomlauser->username);
		}
		$query = "SELECT user_id
			FROM #__users WHERE {$field}={$this->ext_database->Quote($username)}";

		$this->ext_database->setQuery( $query );
		$result = intval($this->ext_database->loadResult());
		return $result;
	}

	/**
	 * Count total number of users to be exported
	 */
	public function countUsers() {
		$query = "SELECT COUNT(*) FROM #__users AS u WHERE user_id > 0 AND u.user_type != 2";
		return $this->getCount ( $query );
	}

	/**
	 * Export users
	 *
	 * Returns list of user extuser objects containing database fields
	 * to #__kunenaimporter_users.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUsers($start = 0, $limit = 0) {
		// phpbb3 user_type: 0=normal, 1=inactive, 2=ignore, 3=founder (super admin)

		$username = $this->login_field ? 'login_name' : 'username';
		$query = "SELECT
			u.user_id AS extid,
			u.username_clean AS extusername,
			u.username AS name,
			u.{$username} AS username,
			u.user_email AS email,
			u.user_password AS password,
			IF(u.user_type=3, 'Administrator', 'Registered') AS usertype,
			IF(b.ban_userid>0 OR u.user_type=1, 1, 0) AS block,
			FROM_UNIXTIME(u.user_regdate) AS registerDate,
			IF(u.user_lastvisit>0, FROM_UNIXTIME(u.user_lastvisit), '0000-00-00 00:00:00') AS lastvisitDate,
			NULL AS params,
			u.user_pass_convert AS password_phpbb2
		FROM #__users AS u
		LEFT JOIN #__banlist AS b ON u.user_id = b.ban_userid
		WHERE user_id > 0 AND u.user_type != 2
		GROUP BY u.user_id
		ORDER BY u.user_id";
		$result = $this->getExportData ( $query, $start, $limit, 'extid' );
		foreach ( $result as &$row ) {
			$this->parseText ( $row->name );
			$this->parseText ( $row->username );
			$this->parseText ( $row->email );

			// Password hash check is described in phpBB3/includes/functions.php: phpbb_check_hash(),
			// _hash_crypt_private() and _hash_encode64() if we want to add plugin for phpBB3 authentication.
			// It works for all phpBB3 passwords, but phpBB2 passwords may need some extra work, which is
			// described in phpBB3/includes/auth/auth_db.php. Basically phpBB2 passwords are encoded by using
			// md5(utf8_to_cp1252(addslashes($password))).
			if ($row->password_phpbb2) {
				$row->password = 'phpbb2::'.$row->password;
			} else {
				$row->password = 'phpbb3::'.$row->password;
			}
		}
		return $result;
	}

	/**
	 * Count total number of user profiles to be exported
	 */
	public function countUserProfile() {
		$query = "SELECT COUNT(*) FROM #__users AS u WHERE user_id > 0 AND u.user_type != 2";
		return $this->getCount ( $query );
	}

	/**
	 * Helper function to get list of all moderators
	 */
	protected function getModerators() {
		static $mods = null;
		if ($mods === null) {
			// Get users in moderator groups
			$query = "SELECT
				u.user_id AS userid,
				ag.forum_id AS catid
			FROM #__acl_roles AS ar
			INNER JOIN #__acl_groups AS ag ON ar.role_id=ag.auth_role_id
			INNER JOIN #__user_group AS ug ON ug.group_id=ag.group_id
			INNER JOIN #__users AS u ON u.user_id=ug.user_id AND u.user_id > 0 AND u.user_type != 2
			WHERE role_type='m_'";
			$result = $this->getExportData ( $query, 0, 10000 );
			$mods = array();
			foreach ($result as $item) {
				$mods[$item->userid][$item->catid] = 1;
			}

			// Get individual moderator rights
			$query = "SELECT
				u.user_id AS userid,
				au.forum_id AS catid
			FROM #__acl_roles AS ar
			INNER JOIN #__acl_users AS au ON ar.role_id=au.auth_role_id
			INNER JOIN #__users AS u ON u.user_id=au.user_id AND u.user_id > 0 AND u.user_type != 2
			WHERE role_type='m_'";
			$result = $this->getExportData ( $query, 0, 10000 );
			foreach ($result as $item) {
				$mods[$item->userid][$item->catid] = 1;
			}
		}
		return $mods;
	}

	/**
	 * Export user profiles
	 *
	 * Returns list of user profile objects containing database fields
	 * to #__kunena_users.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserProfile($start = 0, $limit = 0) {
		$query = "SELECT
			u.user_id AS userid,
			'flat' AS view,
			u.user_sig AS signature,
			0 AS moderator,
			NULL AS banned,
			0 AS ordering,
			u.user_posts AS posts,
			u.user_avatar AS avatar,
			0 AS karma,
			0 AS karma_time,
			0 AS uhits,
			NULL AS personalText,
			0 AS gender,
			u.user_birthday AS birthdate,
			u.user_from AS location,
			u.user_icq AS ICQ,
			u.user_aim AS AIM,
			u.user_yim AS YIM,
			u.user_msnm AS MSN,
			NULL AS SKYPE,
			NULL AS TWITTER,
			NULL AS FACEBOOK,
			u.user_jabber AS GTALK,
			NULL AS MYSPACE,
			NULL AS LINKEDIN,
			NULL AS DELICIOUS,
			NULL AS FRIENDFEED,
			NULL AS DIGG,
			NULL AS BLOGSPOT,
			NULL AS FLICKR,
			NULL AS BEBO,
			u.user_website AS websitename,
			u.user_website AS websiteurl,
			0 AS rank,
			(u.user_allow_viewemail=0) AS hideEmail,
			u.user_allow_viewonline AS showOnline,
			u.user_avatar_type AS avatartype
		FROM #__users AS u
		WHERE u.user_id > 0 AND u.user_type != 2
		ORDER BY u.user_id";
		$result = $this->getExportData ( $query, $start, $limit, 'userid' );
		$moderators = $this->getModerators();

		$config = $this->getConfig();
		$path = $config['avatar_path']->value;
		$salt = $config['avatar_salt']->value;
		foreach ( $result as &$row ) {
			// Assign global moderator status
			if (!empty($moderators[$row->userid])) $row->moderator = 1;
			// Convert bbcode in signature
			if ($row->avatar) {
				switch ($row->avatartype) {
					case 1:
						// Uploaded
						$filename = (int) $row->avatar;
						$ext = substr(strrchr($row->avatar, '.'), 1);
						$row->avatar = "users/{$row->avatar}";
						$row->copypath = "{$this->basepath}/{$path}/{$salt}_{$filename}.{$ext}";
						break;
					case 2:
						// URL not supported
						$row->avatar = '';
						break;
					case 3:
						// Gallery
						$row->avatar = "gallery/{$row->avatar}";
						break;
					default:
						$row->avatar = '';
				}
			}

			$this->parseBBCode ( $row->signature );
			$this->parseText ( $row->location );
			$this->parseText ( $row->AIM );
			$this->parseText ( $row->YIM );
			$this->parseText ( $row->MSN );
			$this->parseText ( $row->GTALK );
			$this->parseText ( $row->websitename );
			$this->parseText ( $row->websiteurl );
		}
		return $result;
	}

	/**
	 * Count total number of sessions to be exported
	 */
	public function countSessions() {
		$query = "SELECT COUNT(*) FROM #__users AS u WHERE user_lastvisit>0";
		return $this->getCount ( $query );
	}

	/**
	 * Export user session information
	 *
	 * Returns list of session objects containing database fields
	 * to #__kunena_sessions.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportSessions($start = 0, $limit = 0) {
		$query = "SELECT
			user_id AS userid,
			NULL AS allowed,
			user_lastmark AS lasttime,
			'na' AS readtopics,
			user_lastvisit AS currvisit
		FROM #__users
		WHERE user_lastvisit>0";
		$result = $this->getExportData ( $query, $start, $limit );
		return $result;
	}

	/**
	 * Count total number of categories to be exported
	 */
	public function countCategories() {
		$query = "SELECT COUNT(*) FROM #__forums";
		return $this->getCount ( $query );
	}

	/**
	 * Export sections and categories
	 *
	 * Returns list of category objects containing database fields
	 * to #__kunena_categories.
	 * All categories without parent are sections.
	 *
	 * NOTE: it's very important to keep category IDs (containing topics) the same!
	 * If there are two tables for sections and categories, change IDs on sections..
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportCategories($start = 0, $limit = 0) {
		$query = "SELECT
			forum_id AS id,
			parent_id AS parent_id,
			forum_name AS name,
			forum_name AS alias,
			0 AS icon_id,
			(forum_status=1) AS locked,
			'joomla.level' AS accesstype,
			0 AS access,
			0 AS pub_access,
			1 AS pub_recurse,
			0 AS admin_access,
			1 AS admin_recurse,
			left_id AS ordering,
			1 AS published,
			null AS channels,
			0 AS checked_out,
			'0000-00-00 00:00:00' AS checked_out_time,
			0 AS review,
			0 AS allow_anonymous,
			0 AS post_anonymous,
			0 AS hits,
			forum_desc AS description,
			forum_rules AS headerdesc,
			'' AS class_sfx,
			1 AS allow_polls,
			'' AS topic_ordering,
			forum_posts AS numPosts,
			forum_topics_real AS numTopics,
			0 AS last_topic_id,
			forum_last_post_id AS last_post_id,
			forum_last_post_time AS last_post_time,
			(LENGTH(forum_desc_bitfield)>0) AS bbcode_desc,
			(LENGTH(forum_rules_bitfield)>0) AS bbcode_header,
			'' AS params
		FROM #__forums ORDER BY id";
		$result = $this->getExportData ( $query, $start, $limit, 'id' );
		foreach ( $result as &$row ) {
			$this->parseText ( $row->name );
			// FIXME: joomla level in J2.5
			// FIXME: remove id
			$row->alias = KunenaRoute::stringURLSafe("{$row->id}-{$row->alias}");
			if ($row->bbcode_desc) $this->parseBBCode ( $row->description );
			else $this->parseText ( $row->description );
			if ($row->bbcode_header) $this->parseBBCode ( $row->headerdesc );
			else $this->parseText ( $row->headerdesc );
		}
		return $result;
	}

	/**
	 * Count total number of moderator columns to be exported
	 */
	public function countUserCategories_Role() {
		$mods = $this->getModerators();
		$result = 0;
		foreach ($mods as $userid=>$item) {
			foreach ($item as $catid=>$value) {
				$result++;
			}
		}
		return $result;
	}

	/**
	 * Export moderator columns
	 *
	 * Returns list of moderator objects containing database fields
	 * to #__kunena_user_categories.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserCategories_Role($start = 0, $limit = 0) {
		$mods = $this->getModerators();
		$result = array();
		foreach ($mods as $userid=>$item) {
			foreach ($item as $catid=>$value) {
				$mod = new StdClass();
				$mod->user_id = $userid;
				$mod->category_id = $catid;
				$mod->role = 1;
				$result[] = $mod;
			}
		}
		$result = array_slice($result, $start, $limit);
		return $result;
	}

	/**
	 * Count total number of all read time columns to be exported
	 */
	public function countUserCategories_Allreadtime() {
		$query = "SELECT COUNT(*) FROM #__forums_track";
		return $this->getCount ( $query );
	}

	/**
	 * Export all read time columns
	 *
	 * Returns list of all userCategory objects containing database fields
	 * to #__kunena_user_categories.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserCategories_Allreadtime($start = 0, $limit = 0) {
		$query = "SELECT
			user_id AS user_id,
			forum_id AS category_id,
			FROM_UNIXTIME(mark_time) AS allreadtime
			FROM #__forums_track";
		return $this->getExportData ( $query, $start, $limit );
	}

	/**
	 * Count total number of category subscription columns to be exported
	 */
	public function countUserCategories_Subscribed() {
		$query = "SELECT COUNT(*) FROM #__forums_watch";
		return $this->getCount ( $query );
	}

	/**
	 * Export category subscription columns
	 *
	 * Returns list of userCategory objects containing database fields
	 * to #__kunena_user_categories.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserCategories_Subscribed($start = 0, $limit = 0) {
		$query = "SELECT
			user_id AS user_id,
			forum_id AS category_id,
			(notify_status+1) AS subscribed
			FROM #__forums_watch";
		return $this->getExportData ( $query, $start, $limit );
	}

	/**
	 * Count total number of topics to be exported
	 */
	public function countTopics() {
		$query = "SELECT COUNT(*) FROM #__topics";
		$count = $this->getCount ( $query );
		return $count;
	}

	/**
	 * Export topics
	 *
	 * Returns list of message objects containing database fields
	 * to #__kunena_topics.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportTopics($start = 0, $limit = 0) {
		$query = "SELECT
		topic_id AS id,
		forum_id AS category_id,
		topic_title AS subject,
		icon_id AS icon_id,
		(topic_status=1) AS locked,
		(topic_approved=0) AS hold,
		(topic_type>0) AS ordering,
		(topic_replies+1) AS posts,
		topic_views AS hits,
		topic_attachment AS attachments,
		0 AS poll_id,
		IF(topic_status=1,topic_moved_id,0) AS moved_id,
		topic_first_post_id AS first_post_id,
		0 AS first_post_time,
		0 AS first_post_userid,
		'' AS first_post_message,
		topic_first_poster_name AS first_post_guest_name,
		topic_last_post_id AS last_post_id,
		0 AS last_post_time,
		0 AS last_post_userid,
		'' AS last_post_message,
		topic_last_poster_name AS last_post_guest_name,
		'' AS params
		FROM #__topics";
		// TODO: add support for announcements and global topics
		$result = $this->getExportData ( $query, $start, $limit );
		foreach ( $result as $key => &$row ) {
			$this->parseText ( $row->subject );
			$this->parseText ( $row->first_post_guest_name );
			$this->parseText ( $row->last_post_guest_name );
			$this->parseBBCode ( $row->first_post_message );
			$this->parseBBCode ( $row->last_post_message );
		}
		return $result;
	}

	/**
	 * Count total number of messages to be exported
	 */
	public function countMessages() {
		$query = "SELECT COUNT(*) FROM #__posts";
		return $this->getCount ( $query );
	}

	/**
	 * Export messages
	 *
	 * Returns list of message objects containing database fields
	 * to #__kunena_messages (and #__kunena_messages_text.message).
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportMessages($start = 0, $limit = 0) {
		$query = "SELECT
			p.post_id AS id,
			IF(p.post_id=t.topic_first_post_id,0,t.topic_first_post_id) AS parent,
			p.topic_id AS thread,
			p.forum_id AS catid,
			IF(p.post_username, p.post_username, u.username) AS name,
			p.poster_id AS userid,
			u.user_email AS email,
			IF(p.post_subject, p.post_subject, t.topic_title) AS subject,
			p.post_time AS time,
			p.poster_ip AS ip,
			0 AS topic_emoticon,
			(t.topic_status=1 AND p.post_id=t.topic_first_post_id) AS locked,
			(p.post_approved=0) AS hold,
			(t.topic_type>0 AND p.post_id=t.topic_first_post_id) AS ordering,
			IF(p.post_id=t.topic_first_post_id,0,t.topic_views) AS hits,
			t.topic_moved_id AS moved,
			p.post_edit_user AS modified_by,
			p.post_edit_time AS modified_time,
			p.post_edit_reason AS modified_reason,
			p.post_text AS message,
			enable_bbcode
		FROM #__posts AS p
		LEFT JOIN #__topics AS t ON p.topic_id = t.topic_id
		LEFT JOIN #__users AS u ON p.poster_id = u.user_id
		ORDER BY p.post_id";
		$result = $this->getExportData ( $query, $start, $limit, 'id' );

		foreach ( $result as &$row ) {
			$this->parseText ( $row->name );
			$this->parseText ( $row->email );
			$this->parseText ( $row->subject );
			if (! $row->modified_time)
				$row->modified_by = 0;
			$this->parseText ( $row->modified_reason );
			if ($row->moved) {
				// TODO: support moved messages (no txt)
				$row->message = "id={$row->moved}";
				$row->moved = 1;
			} else {
				if ($row->enable_bbcode) $this->parseBBcode ( $row->message );
				else $this->parseText ( $row->message );
			}
		}
		return $result;
	}

	/**
	 * Count total polls to be exported
	 */
	public function countPolls() {
		$query="SELECT COUNT(*) FROM #__topics WHERE poll_title!=''";
		return $this->getCount($query);
	}

	/**
	 * Export polls
	 *
	 * Returns list of poll objects containing database fields
	 * to #__kunena_polls.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportPolls($start=0, $limit=0) {
		$query="SELECT
			topic_id AS id,
			poll_title AS title,
			topic_id AS threadid,
			IF(poll_length>0,FROM_UNIXTIME(poll_start+poll_length),'0000-00-00 00:00:00') AS polltimetolive
		FROM #__topics
		WHERE poll_title!=''
		ORDER BY threadid";
		$result = $this->getExportData($query, $start, $limit, 'id');
		return $result;
	}

	/**
	 * Count total poll options to be exported
	 */
	public function countPollsOptions() {
		$query="SELECT COUNT(*) FROM #__poll_options AS o INNER JOIN #__topics AS t ON o.topic_id=t.topic_id";
		return $this->getCount($query);
	}

	/**
	 * Export poll options
	 *
	 * Returns list of poll options objects containing database fields
	 * to #__kunena_polls_options.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportPollsOptions($start=0, $limit=0) {
		$query="SELECT
			0 AS id,
			t.topic_id AS pollid,
			o.poll_option_text AS text,
			o.poll_option_total AS votes
		FROM #__poll_options AS o
		INNER JOIN #__topics AS t ON o.topic_id=t.topic_id
		ORDER BY pollid, o.poll_option_id";
		$result = $this->getExportData($query, $start, $limit);
		return $result;
	}

	/**
	 * Count total poll users to be exported
	 */
	public function countPollsUsers() {
		$query="SELECT COUNT(DISTINCT v.vote_user_id) FROM #__poll_votes AS v INNER JOIN #__topics AS t ON v.topic_id=t.topic_id";
		return $this->getCount($query);
	}

	/**
	 * Export poll users
	 *
	 * Returns list of poll users objects containing database fields
	 * to #__kunena_polls_users.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportPollsUsers($start=0, $limit=0) {
		// WARNING: from unknown reason pollid = threadid!!!
		$query="SELECT
			t.topic_id AS pollid,
			v.vote_user_id AS userid,
			COUNT(*) AS votes,
			'0000-00-00 00:00:00' AS lasttime,
			0 AS lastvote
		FROM #__poll_votes AS v
		INNER JOIN #__topics AS t ON v.topic_id=t.topic_id
		GROUP BY v.vote_user_id";
		$result = $this->getExportData($query, $start, $limit);
		return $result;
	}

	/**
	 * Count total number of all read time columns to be exported
	 */
	public function countUserTopics_Allreadtime() {
		$query = "SELECT COUNT(*) FROM #__topics_track";
		return $this->getCount ( $query );
	}

	/**
	 * Export mark read information for topics
	 *
	 * Returns list of items containing database fields
	 * to #__kunena_user_topics.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserTopics_Allreadtime($start = 0, $limit = 0) {
		$query = "SELECT
			user_id AS user_id,
			topic_id AS topic_id,
			forum_id AS category_id,
			0 AS message_id,
			mark_time AS time
			FROM #__topics_track";
		return $this->getExportData ( $query, $start, $limit );
	}

	/**
	 * Count total number of category subscription columns to be exported
	 */
	public function countUserTopics_Subscribed() {
		$query = "SELECT COUNT(*) FROM #__topics_watch";
		return $this->getCount ( $query );
	}

	/**
	 * Export category subscriptions
	 *
	 * Returns list of items containing database fields
	 * to #__kunena_user_topics.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportUserTopics_Subscribed($start = 0, $limit = 0) {
		$query = "SELECT
			user_id AS user_id,
			topic_id AS topic_id,
			(notify_status+1) AS subscribed
			FROM #__topics_watch";
		return $this->getExportData ( $query, $start, $limit );
	}

	/**
	 * Count total number of attachments to be exported
	 */
	public function countAttachments() {
		$query = "SELECT COUNT(*) FROM #__attachments";
		return $this->getCount ( $query );
	}

	/**
	 * Export attachments in messages
	 *
	 * Returns list of attachment objects containing database fields
	 * to #__kunena_attachments.
	 * NOTE: copies all files found in $row->copyfile (full path) to Kunena.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportAttachments($start = 0, $limit = 0) {
		$query = "SELECT
			attach_id AS id,
			post_msg_id AS mesid,
			poster_id AS userid,
			NULL AS hash,
			filesize AS size,
			'phpbb3' AS folder,
			IF(LENGTH(mimetype)>0,mimetype,extension) AS filetype,
			real_filename AS filename,
			physical_filename AS realfile
		FROM `#__attachments`
		ORDER BY attach_id";
		$result = $this->getExportData ( $query, $start, $limit, 'id' );
		foreach ( $result as &$row ) {
			$row->copypath = "{$this->basepath}/files/{$row->realfile}";
			$row->copypaththumb = "{$this->basepath}/files/thumb_{$row->realfile}";
		}
		return $result;
	}

	/**
	 * Count total number of avatar galleries to be exported
	 */
	public function countAvatarGalleries() {
		return count($this->getAvatarGalleries());
	}

	/**
	 * Export avatar galleries
	 *
	 * Returns list of folder=>fullpath to be copied, where fullpath points
	 * to the directory in the filesystem.
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array
	 */
	public function &exportAvatarGalleries($start = 0, $limit = 0) {
		$galleries = array_slice($this->getAvatarGalleries(), $start, $limit);
		return $galleries;
	}

	/**
	 * Internal function to fetch all avatar galleries
	 *
	 * @return array (folder=>full path, ...)
	 */
	protected function &getAvatarGalleries() {
		$config = $this->getConfig();
		static $galleries = false;
		if ($galleries === false) {
			$galleries = array();
			if (isset($config['avatar_gallery_path'])) {
				$path = "{$this->basepath}/{$config['avatar_gallery_path']->value}";
				$folders = JFolder::folders($path);
				foreach ($folders as $folder) {
					$galleries[$folder] = "{$path}/{$folder}";
				}
			}
		}
		return $galleries;
	}

	/**
	 * Count global configurations to be exported
	 * @return 1
	 */
	public function countConfig() {
		return 1;
	}

	/**
	 * Export global configuration
	 *
	 * @param int $start Pagination start
	 * @param int $limit Pagination limit
	 * @return array (1=>(array(option=>value, ...)))
	 */
	public function &exportConfig($start = 0, $limit = 0) {
		$config = array ();
		if ($start)
			return $config;

		$result = $this->getConfig();

		// Time delta in seconds from UTC (=JFactory::getDate()->toUnix())
		$config['timedelta'] = JFactory::getDate()->toUnix() - time();

		$config ['id'] = 1; // $result['config_id']->value;
		$config ['board_title'] = $result ['sitename']->value;
		$config ['email'] = $result ['board_email']->value;
		$config ['board_offline'] = $result ['board_disable']->value;
		// $config['offline_message'] = null;
		// $config['enablerss'] = null;
		// $config['enablepdf'] = null;
		$config ['threads_per_page'] = $result ['topics_per_page']->value;
		$config ['messages_per_page'] = $result ['posts_per_page']->value;
		// $config['messages_per_page_search'] = null;
		// $config['showhistory'] = null;
		// $config['historylimit'] = null;
		// $config['shownew'] = null;
		// $config['jmambot'] = null;
		$config ['disemoticons'] = $result ['allow_smilies']->value ^ 1;
		// $config['template'] = null;
		// $config['showannouncement'] = null;
		// $config['avataroncat'] = null;
		// $config['catimagepath'] = null;
		// $config['showchildcaticon'] = null;
		// $config['annmodid'] = null;
		// $config['rtewidth'] = null;
		// $config['rteheight'] = null;
		// $config['enableforumjump'] = null;
		// $config['reportmsg'] = null;
		// $config['username'] = null;
		// $config['askemail'] = null;
		// $config['showemail'] = null;
		// $config['showuserstats'] = null;
		// $config['showkarma'] = null;
		// $config['useredit'] = null;
		// $config['useredittime'] = null;
		// $config['useredittimegrace'] = null;
		// $config['editmarkup'] = null;
		$config ['allowsubscriptions'] = $result ['allow_topic_notify']->value;
		// $config['subscriptionschecked'] = null;
		// $config['allowfavorites'] = null;
		// $config['maxsubject'] = null;
		$config ['maxsig'] = $result ['allow_sig']->value ? $result ['max_sig_chars']->value : 0;
		// $config['regonly'] = null;
		$config ['changename'] = $result ['allow_namechange']->value;
		// $config['pubwrite'] = null;
		$config ['floodprotection'] = $result ['flood_interval']->value;
		// $config['mailmod'] = null;
		// $config['mailadmin'] = null;
		// $config['captcha'] = null;
		// $config['mailfull'] = null;
		$config ['allowavatar'] = $result ['allow_avatar_upload']->value || $result ['allow_avatar_local']->value;
		$config ['allowavatarupload'] = $result ['allow_avatar_upload']->value;
		$config ['allowavatargallery'] = $result ['allow_avatar_local']->value;
		// $config['avatarquality'] = null;
		$config ['avatarsize'] = ( int ) ($result ['avatar_filesize']->value / 1000);
		// $config['allowimageupload'] = null;
		// $config['allowimageregupload'] = null;
		$config['imageheight'] = $result ['img_max_height']->value;
		$config['imagewidth'] = $result ['img_max_width']->value;
		// $config['imagesize'] = null;
		// $config['allowfileupload'] = null;
		// $config['allowfileregupload'] = null;
		// $config['filetypes'] = null;
		$config ['filesize'] = ( int ) ($result ['max_filesize']->value / 1000);
		// $config['showranking'] = null;
		// $config['rankimages'] = null;
		// $config['avatar_src'] = null;
		// $config['pm_component'] = null;
		// $config['discussbot'] = null;
		// $config['userlist_rows'] = null;
		// $config['userlist_online'] = null;
		// $config['userlist_avatar'] = null;
		// $config['userlist_name'] = null;
		// $config['userlist_username'] = null;
		// $config['userlist_posts'] = null;
		// $config['userlist_karma'] = null;
		// $config['userlist_email'] = null;
		// $config['userlist_usertype'] = null;
		// $config['userlist_joindate'] = null;
		// $config['userlist_lastvisitdate'] = null;
		// $config['userlist_userhits'] = null;
		// $config['latestcategory'] = null;
		// $config['showstats'] = null;
		// $config['showwhoisonline'] = null;
		// $config['showgenstats'] = null;
		// $config['showpopuserstats'] = null;
		// $config['popusercount'] = null;
		// $config['showpopsubjectstats'] = null;
		// $config['popsubjectcount'] = null;
		// $config['usernamechange'] = null;
		// $config['rules_infb'] = null;
		// $config['rules_cid'] = null;
		// $config['help_infb'] = null;
		// $config['help_cid'] = null;
		// $config['showspoilertag'] = null;
		// $config['showvideotag'] = null;
		// $config['showebaytag'] = null;
		// $config['trimlongurls'] = null;
		// $config['trimlongurlsfront'] = null;
		// $config['trimlongurlsback'] = null;
		// $config['autoembedyoutube'] = null;
		// $config['autoembedebay'] = null;
		// $config['ebaylanguagecode'] = null;
		$config ['fbsessiontimeout'] = $result ['session_length']->value;
		// $config['highlightcode'] = null;
		// $config['rss_type'] = null;
		// $config['rss_timelimit'] = null;
		// $config['rss_limit'] = null;
		// $config['rss_included_categories'] = null;
		// $config['rss_excluded_categories'] = null;
		// $config['rss_specification'] = null;
		// $config['rss_allow_html'] = null;
		// $config['rss_author_format'] = null;
		// $config['rss_author_in_title'] = null;
		// $config['rss_word_count'] = null;
		// $config['rss_old_titles'] = null;
		// $config['rss_cache'] = null;
		$config['fbdefaultpage'] = 'categories';
		// $config['default_sort'] = null;
		// $config['alphauserpointsnumchars'] = null;
		// $config['sef'] = null;
		// $config['sefcats'] = null;
		// $config['sefutf8'] = null;
		// $config['showimgforguest'] = null;
		// $config['showfileforguest'] = null;
		// $config['pollnboptions'] = null;
		// $config['pollallowvoteone'] = null;
		// $config['pollenabled'] = null;
		// $config['poppollscount'] = null;
		// $config['showpoppollstats'] = null;
		// $config['polltimebtvotes'] = null;
		// $config['pollnbvotesbyuser'] = null;
		// $config['pollresultsuserslist'] = null;
		// $config['maxpersotext'] = null;
		// $config['ordering_system'] = null;
		// $config['post_dateformat'] = null;
		// $config['post_dateformat_hover'] = null;
		// $config['hide_ip'] = null;
		// $config['js_actstr_integration'] = null;
		// $config['imagetypes'] = null;
		// $config['checkmimetypes'] = null;
		// $config['imagemimetypes'] = null;
		// $config['imagequality'] = null;
		$config['thumbwidth'] = isset($result ['img_max_thumb_width']->value) ? $result ['img_max_thumb_width']->value : 32;
		$config['thumbheight'] = isset($result ['img_max_thumb_height']->value) ? $result ['img_max_thumb_height']->value : $config['thumbwidth'];
		// $config['hideuserprofileinfo'] = null;
		// $config['integration_access'] = null;
		// $config['integration_login'] = null;
		// $config['integration_avatar'] = null;
		// $config['integration_profile'] = null;
		// $config['integration_private'] = null;
		// $config['integration_activity'] = null;
		// $config['boxghostmessage'] = null;
		// $config['userdeletetmessage'] = null;
		// $config['latestcategory_in'] = null;
		// $config['topicicons'] = null;
		// $config['onlineusers'] = null;
		// $config['debug'] = null;
		// $config['catsautosubscribed'] = null;
		// $config['showbannedreason'] = null;
		// $config['version_check'] = null;
		// $config['showthankyou'] = null;
		// $config['showpopthankyoustats'] = null;
		// $config['popthankscount'] = null;
		// $config['mod_see_deleted'] = null;
		// $config['bbcode_img_secure'] = null;
		// $config['listcat_show_moderators'] = null;
		// $config['lightbox'] = null;
		// $config['activity_limit'] = null;
		// $config['show_list_time'] = null;
		// $config['show_session_type'] = null;
		// $config['show_session_starttime'] = null;
		// $config['userlist_allowed'] = null;
		// $config['userlist_count_users'] = null;
		// $config['enable_threaded_layouts'] = null;
		// $config['category_subscriptions'] = null;
		// $config['topic_subscriptions'] = null;
		// $config['pubprofile'] = null;
		// $config['thankyou_max'] = null;
		$result = array ('1' => $config );
		return $result;
	}
}

if (!function_exists('utf8_clean_string')) {
	/**
	* This function is used to generate a "clean" version of a string.
	* Clean means that it is a case insensitive form (case folding) and that it is normalized (NFC).
	* Additionally a homographs of one character are transformed into one specific character (preferably ASCII
	* if it is an ASCII character).
	*
	* Please be aware that if you change something within this function or within
	* functions used here you need to rebuild/update the username_clean column in the users table. And all other
	* columns that store a clean string otherwise you will break this functionality.
	*
	* @param	string	$text	An unclean string, mabye user input (has to be valid UTF-8!)
	* @return	string			Cleaned up version of the input string
	*/
	function utf8_clean_string($text)
	{
		global $phpbb_root_path, $phpEx;

		static $homographs = array();
		if (empty($homographs)) {
			$homographs = include($phpbb_root_path . 'includes/utf/data/confusables.' . $phpEx);
		}

		$text = utf8_case_fold_nfkc($text);
		$text = strtr($text, $homographs);
		// Other control characters
		$text = preg_replace('#(?:[\x00-\x1F\x7F]+|(?:\xC2[\x80-\x9F])+)#', '', $text);

		// we can use trim here as all the other space characters should have been turned
		// into normal ASCII spaces by now
		return trim($text);
	}
}

if (!function_exists('utf8_case_fold_nfkc')) {
	/**
	* Takes the input and does a "special" case fold. It does minor normalization
	* and returns NFKC compatable text
	*
	* @param	string	$text	text to be case folded
	* @param	string	$option	determines how we will fold the cases
	* @return	string			case folded text
	*/
	function utf8_case_fold_nfkc($text, $option = 'full')
	{
		static $fc_nfkc_closure = array(
			"\xCD\xBA"	=> "\x20\xCE\xB9",
			"\xCF\x92"	=> "\xCF\x85",
			"\xCF\x93"	=> "\xCF\x8D",
			"\xCF\x94"	=> "\xCF\x8B",
			"\xCF\xB2"	=> "\xCF\x83",
			"\xCF\xB9"	=> "\xCF\x83",
			"\xE1\xB4\xAC"	=> "\x61",
			"\xE1\xB4\xAD"	=> "\xC3\xA6",
			"\xE1\xB4\xAE"	=> "\x62",
			"\xE1\xB4\xB0"	=> "\x64",
			"\xE1\xB4\xB1"	=> "\x65",
			"\xE1\xB4\xB2"	=> "\xC7\x9D",
			"\xE1\xB4\xB3"	=> "\x67",
			"\xE1\xB4\xB4"	=> "\x68",
			"\xE1\xB4\xB5"	=> "\x69",
			"\xE1\xB4\xB6"	=> "\x6A",
			"\xE1\xB4\xB7"	=> "\x6B",
			"\xE1\xB4\xB8"	=> "\x6C",
			"\xE1\xB4\xB9"	=> "\x6D",
			"\xE1\xB4\xBA"	=> "\x6E",
			"\xE1\xB4\xBC"	=> "\x6F",
			"\xE1\xB4\xBD"	=> "\xC8\xA3",
			"\xE1\xB4\xBE"	=> "\x70",
			"\xE1\xB4\xBF"	=> "\x72",
			"\xE1\xB5\x80"	=> "\x74",
			"\xE1\xB5\x81"	=> "\x75",
			"\xE1\xB5\x82"	=> "\x77",
			"\xE2\x82\xA8"	=> "\x72\x73",
			"\xE2\x84\x82"	=> "\x63",
			"\xE2\x84\x83"	=> "\xC2\xB0\x63",
			"\xE2\x84\x87"	=> "\xC9\x9B",
			"\xE2\x84\x89"	=> "\xC2\xB0\x66",
			"\xE2\x84\x8B"	=> "\x68",
			"\xE2\x84\x8C"	=> "\x68",
			"\xE2\x84\x8D"	=> "\x68",
			"\xE2\x84\x90"	=> "\x69",
			"\xE2\x84\x91"	=> "\x69",
			"\xE2\x84\x92"	=> "\x6C",
			"\xE2\x84\x95"	=> "\x6E",
			"\xE2\x84\x96"	=> "\x6E\x6F",
			"\xE2\x84\x99"	=> "\x70",
			"\xE2\x84\x9A"	=> "\x71",
			"\xE2\x84\x9B"	=> "\x72",
			"\xE2\x84\x9C"	=> "\x72",
			"\xE2\x84\x9D"	=> "\x72",
			"\xE2\x84\xA0"	=> "\x73\x6D",
			"\xE2\x84\xA1"	=> "\x74\x65\x6C",
			"\xE2\x84\xA2"	=> "\x74\x6D",
			"\xE2\x84\xA4"	=> "\x7A",
			"\xE2\x84\xA8"	=> "\x7A",
			"\xE2\x84\xAC"	=> "\x62",
			"\xE2\x84\xAD"	=> "\x63",
			"\xE2\x84\xB0"	=> "\x65",
			"\xE2\x84\xB1"	=> "\x66",
			"\xE2\x84\xB3"	=> "\x6D",
			"\xE2\x84\xBB"	=> "\x66\x61\x78",
			"\xE2\x84\xBE"	=> "\xCE\xB3",
			"\xE2\x84\xBF"	=> "\xCF\x80",
			"\xE2\x85\x85"	=> "\x64",
			"\xE3\x89\x90"	=> "\x70\x74\x65",
			"\xE3\x8B\x8C"	=> "\x68\x67",
			"\xE3\x8B\x8E"	=> "\x65\x76",
			"\xE3\x8B\x8F"	=> "\x6C\x74\x64",
			"\xE3\x8D\xB1"	=> "\x68\x70\x61",
			"\xE3\x8D\xB3"	=> "\x61\x75",
			"\xE3\x8D\xB5"	=> "\x6F\x76",
			"\xE3\x8D\xBA"	=> "\x69\x75",
			"\xE3\x8E\x80"	=> "\x70\x61",
			"\xE3\x8E\x81"	=> "\x6E\x61",
			"\xE3\x8E\x82"	=> "\xCE\xBC\x61",
			"\xE3\x8E\x83"	=> "\x6D\x61",
			"\xE3\x8E\x84"	=> "\x6B\x61",
			"\xE3\x8E\x85"	=> "\x6B\x62",
			"\xE3\x8E\x86"	=> "\x6D\x62",
			"\xE3\x8E\x87"	=> "\x67\x62",
			"\xE3\x8E\x8A"	=> "\x70\x66",
			"\xE3\x8E\x8B"	=> "\x6E\x66",
			"\xE3\x8E\x8C"	=> "\xCE\xBC\x66",
			"\xE3\x8E\x90"	=> "\x68\x7A",
			"\xE3\x8E\x91"	=> "\x6B\x68\x7A",
			"\xE3\x8E\x92"	=> "\x6D\x68\x7A",
			"\xE3\x8E\x93"	=> "\x67\x68\x7A",
			"\xE3\x8E\x94"	=> "\x74\x68\x7A",
			"\xE3\x8E\xA9"	=> "\x70\x61",
			"\xE3\x8E\xAA"	=> "\x6B\x70\x61",
			"\xE3\x8E\xAB"	=> "\x6D\x70\x61",
			"\xE3\x8E\xAC"	=> "\x67\x70\x61",
			"\xE3\x8E\xB4"	=> "\x70\x76",
			"\xE3\x8E\xB5"	=> "\x6E\x76",
			"\xE3\x8E\xB6"	=> "\xCE\xBC\x76",
			"\xE3\x8E\xB7"	=> "\x6D\x76",
			"\xE3\x8E\xB8"	=> "\x6B\x76",
			"\xE3\x8E\xB9"	=> "\x6D\x76",
			"\xE3\x8E\xBA"	=> "\x70\x77",
			"\xE3\x8E\xBB"	=> "\x6E\x77",
			"\xE3\x8E\xBC"	=> "\xCE\xBC\x77",
			"\xE3\x8E\xBD"	=> "\x6D\x77",
			"\xE3\x8E\xBE"	=> "\x6B\x77",
			"\xE3\x8E\xBF"	=> "\x6D\x77",
			"\xE3\x8F\x80"	=> "\x6B\xCF\x89",
			"\xE3\x8F\x81"	=> "\x6D\xCF\x89",
			"\xE3\x8F\x83"	=> "\x62\x71",
			"\xE3\x8F\x86"	=> "\x63\xE2\x88\x95\x6B\x67",
			"\xE3\x8F\x87"	=> "\x63\x6F\x2E",
			"\xE3\x8F\x88"	=> "\x64\x62",
			"\xE3\x8F\x89"	=> "\x67\x79",
			"\xE3\x8F\x8B"	=> "\x68\x70",
			"\xE3\x8F\x8D"	=> "\x6B\x6B",
			"\xE3\x8F\x8E"	=> "\x6B\x6D",
			"\xE3\x8F\x97"	=> "\x70\x68",
			"\xE3\x8F\x99"	=> "\x70\x70\x6D",
			"\xE3\x8F\x9A"	=> "\x70\x72",
			"\xE3\x8F\x9C"	=> "\x73\x76",
			"\xE3\x8F\x9D"	=> "\x77\x62",
			"\xE3\x8F\x9E"	=> "\x76\xE2\x88\x95\x6D",
			"\xE3\x8F\x9F"	=> "\x61\xE2\x88\x95\x6D",
			"\xF0\x9D\x90\x80"	=> "\x61",
			"\xF0\x9D\x90\x81"	=> "\x62",
			"\xF0\x9D\x90\x82"	=> "\x63",
			"\xF0\x9D\x90\x83"	=> "\x64",
			"\xF0\x9D\x90\x84"	=> "\x65",
			"\xF0\x9D\x90\x85"	=> "\x66",
			"\xF0\x9D\x90\x86"	=> "\x67",
			"\xF0\x9D\x90\x87"	=> "\x68",
			"\xF0\x9D\x90\x88"	=> "\x69",
			"\xF0\x9D\x90\x89"	=> "\x6A",
			"\xF0\x9D\x90\x8A"	=> "\x6B",
			"\xF0\x9D\x90\x8B"	=> "\x6C",
			"\xF0\x9D\x90\x8C"	=> "\x6D",
			"\xF0\x9D\x90\x8D"	=> "\x6E",
			"\xF0\x9D\x90\x8E"	=> "\x6F",
			"\xF0\x9D\x90\x8F"	=> "\x70",
			"\xF0\x9D\x90\x90"	=> "\x71",
			"\xF0\x9D\x90\x91"	=> "\x72",
			"\xF0\x9D\x90\x92"	=> "\x73",
			"\xF0\x9D\x90\x93"	=> "\x74",
			"\xF0\x9D\x90\x94"	=> "\x75",
			"\xF0\x9D\x90\x95"	=> "\x76",
			"\xF0\x9D\x90\x96"	=> "\x77",
			"\xF0\x9D\x90\x97"	=> "\x78",
			"\xF0\x9D\x90\x98"	=> "\x79",
			"\xF0\x9D\x90\x99"	=> "\x7A",
			"\xF0\x9D\x90\xB4"	=> "\x61",
			"\xF0\x9D\x90\xB5"	=> "\x62",
			"\xF0\x9D\x90\xB6"	=> "\x63",
			"\xF0\x9D\x90\xB7"	=> "\x64",
			"\xF0\x9D\x90\xB8"	=> "\x65",
			"\xF0\x9D\x90\xB9"	=> "\x66",
			"\xF0\x9D\x90\xBA"	=> "\x67",
			"\xF0\x9D\x90\xBB"	=> "\x68",
			"\xF0\x9D\x90\xBC"	=> "\x69",
			"\xF0\x9D\x90\xBD"	=> "\x6A",
			"\xF0\x9D\x90\xBE"	=> "\x6B",
			"\xF0\x9D\x90\xBF"	=> "\x6C",
			"\xF0\x9D\x91\x80"	=> "\x6D",
			"\xF0\x9D\x91\x81"	=> "\x6E",
			"\xF0\x9D\x91\x82"	=> "\x6F",
			"\xF0\x9D\x91\x83"	=> "\x70",
			"\xF0\x9D\x91\x84"	=> "\x71",
			"\xF0\x9D\x91\x85"	=> "\x72",
			"\xF0\x9D\x91\x86"	=> "\x73",
			"\xF0\x9D\x91\x87"	=> "\x74",
			"\xF0\x9D\x91\x88"	=> "\x75",
			"\xF0\x9D\x91\x89"	=> "\x76",
			"\xF0\x9D\x91\x8A"	=> "\x77",
			"\xF0\x9D\x91\x8B"	=> "\x78",
			"\xF0\x9D\x91\x8C"	=> "\x79",
			"\xF0\x9D\x91\x8D"	=> "\x7A",
			"\xF0\x9D\x91\xA8"	=> "\x61",
			"\xF0\x9D\x91\xA9"	=> "\x62",
			"\xF0\x9D\x91\xAA"	=> "\x63",
			"\xF0\x9D\x91\xAB"	=> "\x64",
			"\xF0\x9D\x91\xAC"	=> "\x65",
			"\xF0\x9D\x91\xAD"	=> "\x66",
			"\xF0\x9D\x91\xAE"	=> "\x67",
			"\xF0\x9D\x91\xAF"	=> "\x68",
			"\xF0\x9D\x91\xB0"	=> "\x69",
			"\xF0\x9D\x91\xB1"	=> "\x6A",
			"\xF0\x9D\x91\xB2"	=> "\x6B",
			"\xF0\x9D\x91\xB3"	=> "\x6C",
			"\xF0\x9D\x91\xB4"	=> "\x6D",
			"\xF0\x9D\x91\xB5"	=> "\x6E",
			"\xF0\x9D\x91\xB6"	=> "\x6F",
			"\xF0\x9D\x91\xB7"	=> "\x70",
			"\xF0\x9D\x91\xB8"	=> "\x71",
			"\xF0\x9D\x91\xB9"	=> "\x72",
			"\xF0\x9D\x91\xBA"	=> "\x73",
			"\xF0\x9D\x91\xBB"	=> "\x74",
			"\xF0\x9D\x91\xBC"	=> "\x75",
			"\xF0\x9D\x91\xBD"	=> "\x76",
			"\xF0\x9D\x91\xBE"	=> "\x77",
			"\xF0\x9D\x91\xBF"	=> "\x78",
			"\xF0\x9D\x92\x80"	=> "\x79",
			"\xF0\x9D\x92\x81"	=> "\x7A",
			"\xF0\x9D\x92\x9C"	=> "\x61",
			"\xF0\x9D\x92\x9E"	=> "\x63",
			"\xF0\x9D\x92\x9F"	=> "\x64",
			"\xF0\x9D\x92\xA2"	=> "\x67",
			"\xF0\x9D\x92\xA5"	=> "\x6A",
			"\xF0\x9D\x92\xA6"	=> "\x6B",
			"\xF0\x9D\x92\xA9"	=> "\x6E",
			"\xF0\x9D\x92\xAA"	=> "\x6F",
			"\xF0\x9D\x92\xAB"	=> "\x70",
			"\xF0\x9D\x92\xAC"	=> "\x71",
			"\xF0\x9D\x92\xAE"	=> "\x73",
			"\xF0\x9D\x92\xAF"	=> "\x74",
			"\xF0\x9D\x92\xB0"	=> "\x75",
			"\xF0\x9D\x92\xB1"	=> "\x76",
			"\xF0\x9D\x92\xB2"	=> "\x77",
			"\xF0\x9D\x92\xB3"	=> "\x78",
			"\xF0\x9D\x92\xB4"	=> "\x79",
			"\xF0\x9D\x92\xB5"	=> "\x7A",
			"\xF0\x9D\x93\x90"	=> "\x61",
			"\xF0\x9D\x93\x91"	=> "\x62",
			"\xF0\x9D\x93\x92"	=> "\x63",
			"\xF0\x9D\x93\x93"	=> "\x64",
			"\xF0\x9D\x93\x94"	=> "\x65",
			"\xF0\x9D\x93\x95"	=> "\x66",
			"\xF0\x9D\x93\x96"	=> "\x67",
			"\xF0\x9D\x93\x97"	=> "\x68",
			"\xF0\x9D\x93\x98"	=> "\x69",
			"\xF0\x9D\x93\x99"	=> "\x6A",
			"\xF0\x9D\x93\x9A"	=> "\x6B",
			"\xF0\x9D\x93\x9B"	=> "\x6C",
			"\xF0\x9D\x93\x9C"	=> "\x6D",
			"\xF0\x9D\x93\x9D"	=> "\x6E",
			"\xF0\x9D\x93\x9E"	=> "\x6F",
			"\xF0\x9D\x93\x9F"	=> "\x70",
			"\xF0\x9D\x93\xA0"	=> "\x71",
			"\xF0\x9D\x93\xA1"	=> "\x72",
			"\xF0\x9D\x93\xA2"	=> "\x73",
			"\xF0\x9D\x93\xA3"	=> "\x74",
			"\xF0\x9D\x93\xA4"	=> "\x75",
			"\xF0\x9D\x93\xA5"	=> "\x76",
			"\xF0\x9D\x93\xA6"	=> "\x77",
			"\xF0\x9D\x93\xA7"	=> "\x78",
			"\xF0\x9D\x93\xA8"	=> "\x79",
			"\xF0\x9D\x93\xA9"	=> "\x7A",
			"\xF0\x9D\x94\x84"	=> "\x61",
			"\xF0\x9D\x94\x85"	=> "\x62",
			"\xF0\x9D\x94\x87"	=> "\x64",
			"\xF0\x9D\x94\x88"	=> "\x65",
			"\xF0\x9D\x94\x89"	=> "\x66",
			"\xF0\x9D\x94\x8A"	=> "\x67",
			"\xF0\x9D\x94\x8D"	=> "\x6A",
			"\xF0\x9D\x94\x8E"	=> "\x6B",
			"\xF0\x9D\x94\x8F"	=> "\x6C",
			"\xF0\x9D\x94\x90"	=> "\x6D",
			"\xF0\x9D\x94\x91"	=> "\x6E",
			"\xF0\x9D\x94\x92"	=> "\x6F",
			"\xF0\x9D\x94\x93"	=> "\x70",
			"\xF0\x9D\x94\x94"	=> "\x71",
			"\xF0\x9D\x94\x96"	=> "\x73",
			"\xF0\x9D\x94\x97"	=> "\x74",
			"\xF0\x9D\x94\x98"	=> "\x75",
			"\xF0\x9D\x94\x99"	=> "\x76",
			"\xF0\x9D\x94\x9A"	=> "\x77",
			"\xF0\x9D\x94\x9B"	=> "\x78",
			"\xF0\x9D\x94\x9C"	=> "\x79",
			"\xF0\x9D\x94\xB8"	=> "\x61",
			"\xF0\x9D\x94\xB9"	=> "\x62",
			"\xF0\x9D\x94\xBB"	=> "\x64",
			"\xF0\x9D\x94\xBC"	=> "\x65",
			"\xF0\x9D\x94\xBD"	=> "\x66",
			"\xF0\x9D\x94\xBE"	=> "\x67",
			"\xF0\x9D\x95\x80"	=> "\x69",
			"\xF0\x9D\x95\x81"	=> "\x6A",
			"\xF0\x9D\x95\x82"	=> "\x6B",
			"\xF0\x9D\x95\x83"	=> "\x6C",
			"\xF0\x9D\x95\x84"	=> "\x6D",
			"\xF0\x9D\x95\x86"	=> "\x6F",
			"\xF0\x9D\x95\x8A"	=> "\x73",
			"\xF0\x9D\x95\x8B"	=> "\x74",
			"\xF0\x9D\x95\x8C"	=> "\x75",
			"\xF0\x9D\x95\x8D"	=> "\x76",
			"\xF0\x9D\x95\x8E"	=> "\x77",
			"\xF0\x9D\x95\x8F"	=> "\x78",
			"\xF0\x9D\x95\x90"	=> "\x79",
			"\xF0\x9D\x95\xAC"	=> "\x61",
			"\xF0\x9D\x95\xAD"	=> "\x62",
			"\xF0\x9D\x95\xAE"	=> "\x63",
			"\xF0\x9D\x95\xAF"	=> "\x64",
			"\xF0\x9D\x95\xB0"	=> "\x65",
			"\xF0\x9D\x95\xB1"	=> "\x66",
			"\xF0\x9D\x95\xB2"	=> "\x67",
			"\xF0\x9D\x95\xB3"	=> "\x68",
			"\xF0\x9D\x95\xB4"	=> "\x69",
			"\xF0\x9D\x95\xB5"	=> "\x6A",
			"\xF0\x9D\x95\xB6"	=> "\x6B",
			"\xF0\x9D\x95\xB7"	=> "\x6C",
			"\xF0\x9D\x95\xB8"	=> "\x6D",
			"\xF0\x9D\x95\xB9"	=> "\x6E",
			"\xF0\x9D\x95\xBA"	=> "\x6F",
			"\xF0\x9D\x95\xBB"	=> "\x70",
			"\xF0\x9D\x95\xBC"	=> "\x71",
			"\xF0\x9D\x95\xBD"	=> "\x72",
			"\xF0\x9D\x95\xBE"	=> "\x73",
			"\xF0\x9D\x95\xBF"	=> "\x74",
			"\xF0\x9D\x96\x80"	=> "\x75",
			"\xF0\x9D\x96\x81"	=> "\x76",
			"\xF0\x9D\x96\x82"	=> "\x77",
			"\xF0\x9D\x96\x83"	=> "\x78",
			"\xF0\x9D\x96\x84"	=> "\x79",
			"\xF0\x9D\x96\x85"	=> "\x7A",
			"\xF0\x9D\x96\xA0"	=> "\x61",
			"\xF0\x9D\x96\xA1"	=> "\x62",
			"\xF0\x9D\x96\xA2"	=> "\x63",
			"\xF0\x9D\x96\xA3"	=> "\x64",
			"\xF0\x9D\x96\xA4"	=> "\x65",
			"\xF0\x9D\x96\xA5"	=> "\x66",
			"\xF0\x9D\x96\xA6"	=> "\x67",
			"\xF0\x9D\x96\xA7"	=> "\x68",
			"\xF0\x9D\x96\xA8"	=> "\x69",
			"\xF0\x9D\x96\xA9"	=> "\x6A",
			"\xF0\x9D\x96\xAA"	=> "\x6B",
			"\xF0\x9D\x96\xAB"	=> "\x6C",
			"\xF0\x9D\x96\xAC"	=> "\x6D",
			"\xF0\x9D\x96\xAD"	=> "\x6E",
			"\xF0\x9D\x96\xAE"	=> "\x6F",
			"\xF0\x9D\x96\xAF"	=> "\x70",
			"\xF0\x9D\x96\xB0"	=> "\x71",
			"\xF0\x9D\x96\xB1"	=> "\x72",
			"\xF0\x9D\x96\xB2"	=> "\x73",
			"\xF0\x9D\x96\xB3"	=> "\x74",
			"\xF0\x9D\x96\xB4"	=> "\x75",
			"\xF0\x9D\x96\xB5"	=> "\x76",
			"\xF0\x9D\x96\xB6"	=> "\x77",
			"\xF0\x9D\x96\xB7"	=> "\x78",
			"\xF0\x9D\x96\xB8"	=> "\x79",
			"\xF0\x9D\x96\xB9"	=> "\x7A",
			"\xF0\x9D\x97\x94"	=> "\x61",
			"\xF0\x9D\x97\x95"	=> "\x62",
			"\xF0\x9D\x97\x96"	=> "\x63",
			"\xF0\x9D\x97\x97"	=> "\x64",
			"\xF0\x9D\x97\x98"	=> "\x65",
			"\xF0\x9D\x97\x99"	=> "\x66",
			"\xF0\x9D\x97\x9A"	=> "\x67",
			"\xF0\x9D\x97\x9B"	=> "\x68",
			"\xF0\x9D\x97\x9C"	=> "\x69",
			"\xF0\x9D\x97\x9D"	=> "\x6A",
			"\xF0\x9D\x97\x9E"	=> "\x6B",
			"\xF0\x9D\x97\x9F"	=> "\x6C",
			"\xF0\x9D\x97\xA0"	=> "\x6D",
			"\xF0\x9D\x97\xA1"	=> "\x6E",
			"\xF0\x9D\x97\xA2"	=> "\x6F",
			"\xF0\x9D\x97\xA3"	=> "\x70",
			"\xF0\x9D\x97\xA4"	=> "\x71",
			"\xF0\x9D\x97\xA5"	=> "\x72",
			"\xF0\x9D\x97\xA6"	=> "\x73",
			"\xF0\x9D\x97\xA7"	=> "\x74",
			"\xF0\x9D\x97\xA8"	=> "\x75",
			"\xF0\x9D\x97\xA9"	=> "\x76",
			"\xF0\x9D\x97\xAA"	=> "\x77",
			"\xF0\x9D\x97\xAB"	=> "\x78",
			"\xF0\x9D\x97\xAC"	=> "\x79",
			"\xF0\x9D\x97\xAD"	=> "\x7A",
			"\xF0\x9D\x98\x88"	=> "\x61",
			"\xF0\x9D\x98\x89"	=> "\x62",
			"\xF0\x9D\x98\x8A"	=> "\x63",
			"\xF0\x9D\x98\x8B"	=> "\x64",
			"\xF0\x9D\x98\x8C"	=> "\x65",
			"\xF0\x9D\x98\x8D"	=> "\x66",
			"\xF0\x9D\x98\x8E"	=> "\x67",
			"\xF0\x9D\x98\x8F"	=> "\x68",
			"\xF0\x9D\x98\x90"	=> "\x69",
			"\xF0\x9D\x98\x91"	=> "\x6A",
			"\xF0\x9D\x98\x92"	=> "\x6B",
			"\xF0\x9D\x98\x93"	=> "\x6C",
			"\xF0\x9D\x98\x94"	=> "\x6D",
			"\xF0\x9D\x98\x95"	=> "\x6E",
			"\xF0\x9D\x98\x96"	=> "\x6F",
			"\xF0\x9D\x98\x97"	=> "\x70",
			"\xF0\x9D\x98\x98"	=> "\x71",
			"\xF0\x9D\x98\x99"	=> "\x72",
			"\xF0\x9D\x98\x9A"	=> "\x73",
			"\xF0\x9D\x98\x9B"	=> "\x74",
			"\xF0\x9D\x98\x9C"	=> "\x75",
			"\xF0\x9D\x98\x9D"	=> "\x76",
			"\xF0\x9D\x98\x9E"	=> "\x77",
			"\xF0\x9D\x98\x9F"	=> "\x78",
			"\xF0\x9D\x98\xA0"	=> "\x79",
			"\xF0\x9D\x98\xA1"	=> "\x7A",
			"\xF0\x9D\x98\xBC"	=> "\x61",
			"\xF0\x9D\x98\xBD"	=> "\x62",
			"\xF0\x9D\x98\xBE"	=> "\x63",
			"\xF0\x9D\x98\xBF"	=> "\x64",
			"\xF0\x9D\x99\x80"	=> "\x65",
			"\xF0\x9D\x99\x81"	=> "\x66",
			"\xF0\x9D\x99\x82"	=> "\x67",
			"\xF0\x9D\x99\x83"	=> "\x68",
			"\xF0\x9D\x99\x84"	=> "\x69",
			"\xF0\x9D\x99\x85"	=> "\x6A",
			"\xF0\x9D\x99\x86"	=> "\x6B",
			"\xF0\x9D\x99\x87"	=> "\x6C",
			"\xF0\x9D\x99\x88"	=> "\x6D",
			"\xF0\x9D\x99\x89"	=> "\x6E",
			"\xF0\x9D\x99\x8A"	=> "\x6F",
			"\xF0\x9D\x99\x8B"	=> "\x70",
			"\xF0\x9D\x99\x8C"	=> "\x71",
			"\xF0\x9D\x99\x8D"	=> "\x72",
			"\xF0\x9D\x99\x8E"	=> "\x73",
			"\xF0\x9D\x99\x8F"	=> "\x74",
			"\xF0\x9D\x99\x90"	=> "\x75",
			"\xF0\x9D\x99\x91"	=> "\x76",
			"\xF0\x9D\x99\x92"	=> "\x77",
			"\xF0\x9D\x99\x93"	=> "\x78",
			"\xF0\x9D\x99\x94"	=> "\x79",
			"\xF0\x9D\x99\x95"	=> "\x7A",
			"\xF0\x9D\x99\xB0"	=> "\x61",
			"\xF0\x9D\x99\xB1"	=> "\x62",
			"\xF0\x9D\x99\xB2"	=> "\x63",
			"\xF0\x9D\x99\xB3"	=> "\x64",
			"\xF0\x9D\x99\xB4"	=> "\x65",
			"\xF0\x9D\x99\xB5"	=> "\x66",
			"\xF0\x9D\x99\xB6"	=> "\x67",
			"\xF0\x9D\x99\xB7"	=> "\x68",
			"\xF0\x9D\x99\xB8"	=> "\x69",
			"\xF0\x9D\x99\xB9"	=> "\x6A",
			"\xF0\x9D\x99\xBA"	=> "\x6B",
			"\xF0\x9D\x99\xBB"	=> "\x6C",
			"\xF0\x9D\x99\xBC"	=> "\x6D",
			"\xF0\x9D\x99\xBD"	=> "\x6E",
			"\xF0\x9D\x99\xBE"	=> "\x6F",
			"\xF0\x9D\x99\xBF"	=> "\x70",
			"\xF0\x9D\x9A\x80"	=> "\x71",
			"\xF0\x9D\x9A\x81"	=> "\x72",
			"\xF0\x9D\x9A\x82"	=> "\x73",
			"\xF0\x9D\x9A\x83"	=> "\x74",
			"\xF0\x9D\x9A\x84"	=> "\x75",
			"\xF0\x9D\x9A\x85"	=> "\x76",
			"\xF0\x9D\x9A\x86"	=> "\x77",
			"\xF0\x9D\x9A\x87"	=> "\x78",
			"\xF0\x9D\x9A\x88"	=> "\x79",
			"\xF0\x9D\x9A\x89"	=> "\x7A",
			"\xF0\x9D\x9A\xA8"	=> "\xCE\xB1",
			"\xF0\x9D\x9A\xA9"	=> "\xCE\xB2",
			"\xF0\x9D\x9A\xAA"	=> "\xCE\xB3",
			"\xF0\x9D\x9A\xAB"	=> "\xCE\xB4",
			"\xF0\x9D\x9A\xAC"	=> "\xCE\xB5",
			"\xF0\x9D\x9A\xAD"	=> "\xCE\xB6",
			"\xF0\x9D\x9A\xAE"	=> "\xCE\xB7",
			"\xF0\x9D\x9A\xAF"	=> "\xCE\xB8",
			"\xF0\x9D\x9A\xB0"	=> "\xCE\xB9",
			"\xF0\x9D\x9A\xB1"	=> "\xCE\xBA",
			"\xF0\x9D\x9A\xB2"	=> "\xCE\xBB",
			"\xF0\x9D\x9A\xB3"	=> "\xCE\xBC",
			"\xF0\x9D\x9A\xB4"	=> "\xCE\xBD",
			"\xF0\x9D\x9A\xB5"	=> "\xCE\xBE",
			"\xF0\x9D\x9A\xB6"	=> "\xCE\xBF",
			"\xF0\x9D\x9A\xB7"	=> "\xCF\x80",
			"\xF0\x9D\x9A\xB8"	=> "\xCF\x81",
			"\xF0\x9D\x9A\xB9"	=> "\xCE\xB8",
			"\xF0\x9D\x9A\xBA"	=> "\xCF\x83",
			"\xF0\x9D\x9A\xBB"	=> "\xCF\x84",
			"\xF0\x9D\x9A\xBC"	=> "\xCF\x85",
			"\xF0\x9D\x9A\xBD"	=> "\xCF\x86",
			"\xF0\x9D\x9A\xBE"	=> "\xCF\x87",
			"\xF0\x9D\x9A\xBF"	=> "\xCF\x88",
			"\xF0\x9D\x9B\x80"	=> "\xCF\x89",
			"\xF0\x9D\x9B\x93"	=> "\xCF\x83",
			"\xF0\x9D\x9B\xA2"	=> "\xCE\xB1",
			"\xF0\x9D\x9B\xA3"	=> "\xCE\xB2",
			"\xF0\x9D\x9B\xA4"	=> "\xCE\xB3",
			"\xF0\x9D\x9B\xA5"	=> "\xCE\xB4",
			"\xF0\x9D\x9B\xA6"	=> "\xCE\xB5",
			"\xF0\x9D\x9B\xA7"	=> "\xCE\xB6",
			"\xF0\x9D\x9B\xA8"	=> "\xCE\xB7",
			"\xF0\x9D\x9B\xA9"	=> "\xCE\xB8",
			"\xF0\x9D\x9B\xAA"	=> "\xCE\xB9",
			"\xF0\x9D\x9B\xAB"	=> "\xCE\xBA",
			"\xF0\x9D\x9B\xAC"	=> "\xCE\xBB",
			"\xF0\x9D\x9B\xAD"	=> "\xCE\xBC",
			"\xF0\x9D\x9B\xAE"	=> "\xCE\xBD",
			"\xF0\x9D\x9B\xAF"	=> "\xCE\xBE",
			"\xF0\x9D\x9B\xB0"	=> "\xCE\xBF",
			"\xF0\x9D\x9B\xB1"	=> "\xCF\x80",
			"\xF0\x9D\x9B\xB2"	=> "\xCF\x81",
			"\xF0\x9D\x9B\xB3"	=> "\xCE\xB8",
			"\xF0\x9D\x9B\xB4"	=> "\xCF\x83",
			"\xF0\x9D\x9B\xB5"	=> "\xCF\x84",
			"\xF0\x9D\x9B\xB6"	=> "\xCF\x85",
			"\xF0\x9D\x9B\xB7"	=> "\xCF\x86",
			"\xF0\x9D\x9B\xB8"	=> "\xCF\x87",
			"\xF0\x9D\x9B\xB9"	=> "\xCF\x88",
			"\xF0\x9D\x9B\xBA"	=> "\xCF\x89",
			"\xF0\x9D\x9C\x8D"	=> "\xCF\x83",
			"\xF0\x9D\x9C\x9C"	=> "\xCE\xB1",
			"\xF0\x9D\x9C\x9D"	=> "\xCE\xB2",
			"\xF0\x9D\x9C\x9E"	=> "\xCE\xB3",
			"\xF0\x9D\x9C\x9F"	=> "\xCE\xB4",
			"\xF0\x9D\x9C\xA0"	=> "\xCE\xB5",
			"\xF0\x9D\x9C\xA1"	=> "\xCE\xB6",
			"\xF0\x9D\x9C\xA2"	=> "\xCE\xB7",
			"\xF0\x9D\x9C\xA3"	=> "\xCE\xB8",
			"\xF0\x9D\x9C\xA4"	=> "\xCE\xB9",
			"\xF0\x9D\x9C\xA5"	=> "\xCE\xBA",
			"\xF0\x9D\x9C\xA6"	=> "\xCE\xBB",
			"\xF0\x9D\x9C\xA7"	=> "\xCE\xBC",
			"\xF0\x9D\x9C\xA8"	=> "\xCE\xBD",
			"\xF0\x9D\x9C\xA9"	=> "\xCE\xBE",
			"\xF0\x9D\x9C\xAA"	=> "\xCE\xBF",
			"\xF0\x9D\x9C\xAB"	=> "\xCF\x80",
			"\xF0\x9D\x9C\xAC"	=> "\xCF\x81",
			"\xF0\x9D\x9C\xAD"	=> "\xCE\xB8",
			"\xF0\x9D\x9C\xAE"	=> "\xCF\x83",
			"\xF0\x9D\x9C\xAF"	=> "\xCF\x84",
			"\xF0\x9D\x9C\xB0"	=> "\xCF\x85",
			"\xF0\x9D\x9C\xB1"	=> "\xCF\x86",
			"\xF0\x9D\x9C\xB2"	=> "\xCF\x87",
			"\xF0\x9D\x9C\xB3"	=> "\xCF\x88",
			"\xF0\x9D\x9C\xB4"	=> "\xCF\x89",
			"\xF0\x9D\x9D\x87"	=> "\xCF\x83",
			"\xF0\x9D\x9D\x96"	=> "\xCE\xB1",
			"\xF0\x9D\x9D\x97"	=> "\xCE\xB2",
			"\xF0\x9D\x9D\x98"	=> "\xCE\xB3",
			"\xF0\x9D\x9D\x99"	=> "\xCE\xB4",
			"\xF0\x9D\x9D\x9A"	=> "\xCE\xB5",
			"\xF0\x9D\x9D\x9B"	=> "\xCE\xB6",
			"\xF0\x9D\x9D\x9C"	=> "\xCE\xB7",
			"\xF0\x9D\x9D\x9D"	=> "\xCE\xB8",
			"\xF0\x9D\x9D\x9E"	=> "\xCE\xB9",
			"\xF0\x9D\x9D\x9F"	=> "\xCE\xBA",
			"\xF0\x9D\x9D\xA0"	=> "\xCE\xBB",
			"\xF0\x9D\x9D\xA1"	=> "\xCE\xBC",
			"\xF0\x9D\x9D\xA2"	=> "\xCE\xBD",
			"\xF0\x9D\x9D\xA3"	=> "\xCE\xBE",
			"\xF0\x9D\x9D\xA4"	=> "\xCE\xBF",
			"\xF0\x9D\x9D\xA5"	=> "\xCF\x80",
			"\xF0\x9D\x9D\xA6"	=> "\xCF\x81",
			"\xF0\x9D\x9D\xA7"	=> "\xCE\xB8",
			"\xF0\x9D\x9D\xA8"	=> "\xCF\x83",
			"\xF0\x9D\x9D\xA9"	=> "\xCF\x84",
			"\xF0\x9D\x9D\xAA"	=> "\xCF\x85",
			"\xF0\x9D\x9D\xAB"	=> "\xCF\x86",
			"\xF0\x9D\x9D\xAC"	=> "\xCF\x87",
			"\xF0\x9D\x9D\xAD"	=> "\xCF\x88",
			"\xF0\x9D\x9D\xAE"	=> "\xCF\x89",
			"\xF0\x9D\x9E\x81"	=> "\xCF\x83",
			"\xF0\x9D\x9E\x90"	=> "\xCE\xB1",
			"\xF0\x9D\x9E\x91"	=> "\xCE\xB2",
			"\xF0\x9D\x9E\x92"	=> "\xCE\xB3",
			"\xF0\x9D\x9E\x93"	=> "\xCE\xB4",
			"\xF0\x9D\x9E\x94"	=> "\xCE\xB5",
			"\xF0\x9D\x9E\x95"	=> "\xCE\xB6",
			"\xF0\x9D\x9E\x96"	=> "\xCE\xB7",
			"\xF0\x9D\x9E\x97"	=> "\xCE\xB8",
			"\xF0\x9D\x9E\x98"	=> "\xCE\xB9",
			"\xF0\x9D\x9E\x99"	=> "\xCE\xBA",
			"\xF0\x9D\x9E\x9A"	=> "\xCE\xBB",
			"\xF0\x9D\x9E\x9B"	=> "\xCE\xBC",
			"\xF0\x9D\x9E\x9C"	=> "\xCE\xBD",
			"\xF0\x9D\x9E\x9D"	=> "\xCE\xBE",
			"\xF0\x9D\x9E\x9E"	=> "\xCE\xBF",
			"\xF0\x9D\x9E\x9F"	=> "\xCF\x80",
			"\xF0\x9D\x9E\xA0"	=> "\xCF\x81",
			"\xF0\x9D\x9E\xA1"	=> "\xCE\xB8",
			"\xF0\x9D\x9E\xA2"	=> "\xCF\x83",
			"\xF0\x9D\x9E\xA3"	=> "\xCF\x84",
			"\xF0\x9D\x9E\xA4"	=> "\xCF\x85",
			"\xF0\x9D\x9E\xA5"	=> "\xCF\x86",
			"\xF0\x9D\x9E\xA6"	=> "\xCF\x87",
			"\xF0\x9D\x9E\xA7"	=> "\xCF\x88",
			"\xF0\x9D\x9E\xA8"	=> "\xCF\x89",
			"\xF0\x9D\x9E\xBB"	=> "\xCF\x83",
			"\xF0\x9D\x9F\x8A"	=> "\xCF\x9D",
		);
		global $phpbb_root_path, $phpEx;

		// do the case fold
		$text = utf8_case_fold($text, $option);

		if (!class_exists('utf_normalizer')) {
			global $phpbb_root_path, $phpEx;
			include($phpbb_root_path . 'includes/utf/utf_normalizer.' . $phpEx);
		}

		// convert to NFKC
		utf_normalizer::nfkc($text);

		// FC_NFKC_Closure, http://www.unicode.org/Public/5.0.0/ucd/DerivedNormalizationProps.txt
		$text = strtr($text, $fc_nfkc_closure);

		return $text;
	}
}

if (!function_exists('utf8_case_fold')) {
	/**
	* Case folds a unicode string as per Unicode 5.0, section 3.13
	*
	* @param	string	$text	text to be case folded
	* @param	string	$option	determines how we will fold the cases
	* @return	string			case folded text
	*/
	function utf8_case_fold($text, $option = 'full')
	{
		static $uniarray = array();
		global $phpbb_root_path, $phpEx;

		// common is always set
		if (!isset($uniarray['c'])) {
			$uniarray['c'] = include($phpbb_root_path . 'includes/utf/data/case_fold_c.' . $phpEx);
		}

		// only set full if we need to
		if ($option === 'full' && !isset($uniarray['f'])) {
			$uniarray['f'] = include($phpbb_root_path . 'includes/utf/data/case_fold_f.' . $phpEx);
		}

		// only set simple if we need to
		if ($option !== 'full' && !isset($uniarray['s'])) {
			$uniarray['s'] = include($phpbb_root_path . 'includes/utf/data/case_fold_s.' . $phpEx);
		}

		// common is always replaced
		$text = strtr($text, $uniarray['c']);

		if ($option === 'full') {
			// full replaces a character with multiple characters
			$text = strtr($text, $uniarray['f']);
		} else {
			// simple replaces a character with another character
			$text = strtr($text, $uniarray['s']);
		}

		return $text;
	}
}
