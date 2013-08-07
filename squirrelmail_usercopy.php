<?php

/**
 * Copy a new users identity and settings from a nearby Squirrelmail installation
 *
 * @version 1.4.1-MN
 * @author Thomas Bruederli, Johannes Hessellund, pommi, Thomas Lueder, Markus Neubauer
 * @modified sourabh at xuniltech.com
 */
 
 /*** Changes made:
 * 1. Added support for squirrelmail groups
 * 2. Handled email addresses without domain and use the domain name of the user which had logged in.
 */
 
class squirrelmail_usercopy extends rcube_plugin
{
  public $task = 'login';

	private $prefs = null;
	private $identities_level = 0;
	private $abook = array();
	private $abook_group = array();

	public function init()
	{
		$this->add_hook('user_create', array($this, 'create_user'));
		$this->add_hook('identity_create', array($this, 'create_identity'));
	}

	public function create_user($p)
	{
		$rcmail = rcmail::get_instance();

		// Read plugin's config
		$this->initialize();

		// read prefs and add email address
		$this->read_squirrel_prefs($p['user']);
		if (($this->identities_level == 0 || $this->identities_level == 2) && $rcmail->config->get('squirrelmail_set_alias') && $this->prefs['email_address'])
			$p['user_email'] = $this->prefs['email_address'];
		return $p;
	}

	public function create_identity($p)
	{
		$rcmail = rcmail::get_instance();

		// prefs are set in create_user()
		if ($this->prefs) {
			if ($this->prefs['full_name'])
				$p['record']['name'] = $this->prefs['full_name'];
			if (($this->identities_level == 0 || $this->identities_level == 2) && $this->prefs['email_address'])
				$p['record']['email'] = $this->prefs['email_address'];
			if ($this->prefs['___signature___'])
				$p['record']['signature'] = $this->prefs['___signature___'];
			if ($this->prefs['reply_to']) 
				$p['record']['reply-to'] = $this->prefs['reply_to']; 
			if (($this->identities_level == 0 || $this->identities_level == 1) && isset($this->prefs['identities']) && $this->prefs['identities'] > 1) {
				for ($i=1; $i < $this->prefs['identities']; $i++) {
					unset($ident_data);
					$ident_data = array('name' => '', 'email' => ''); // required data
					if ($this->prefs['full_name'.$i])
						$ident_data['name'] = $this->prefs['full_name'.$i];
					if ($this->identities_level == 0 && $this->prefs['email_address'.$i])
						$ident_data['email'] = $this->prefs['email_address'.$i];
					else
						$ident_data['email'] = $p['record']['email'];
					if ($this->prefs['reply_to'.$i])
						$ident_data['reply-to'] = $this->prefs['reply_to'.$i];
					if ($this->prefs['___sig'.$i.'___'])
						$ident_data['signature'] = $this->prefs['___sig'.$i.'___'];
					// insert identity
					$identid = $rcmail->user->insert_identity($ident_data);
				}
			}
			
			// copy address book
			$contacts = $rcmail->get_address_book(null, true);
			if ($contacts && count($this->abook)) {
				foreach ($this->abook as $rec) {
				    // #1487096 handle multi-address and/or too long items
				    $rec['email'] = array_shift(explode(';', $rec['email']));
				    if (check_email(rcube_idn_to_ascii($rec['email']))) {
				    	$rec['email'] = rcube_idn_to_utf8($rec['email']);
    					$contacts->insert($rec, true);
				    }
				}

				// copy address groups
				$this_group="";
				foreach ($this->abook_group as $rec) {

					if ( $this_group != $rec['addressgroup'] ) {
						$group = $contacts->create_group($rec['addressgroup']);
						$this_group = $rec['addressgroup'];
					}
				
					 // #1487096 handle multi-address and/or too long items
					 $rec['email'] = array_shift(explode(';', $rec['email']));
					 if (check_email(idn_to_ascii($rec['email']))) {
					 	$rec['email'] = idn_to_utf8($rec['email']);
					 	$contact = $contacts->search('email', $rec['email'], true);
						$contact = $contact->next();
					 	$contacts->add_to_group($group['id'], $contact['ID']);
					}
				}
			}

			// mark identity as complete for following hooks
			$p['complete'] = true;
		}

		return $p;
	}

	private function initialize()
	{
		$rcmail = rcmail::get_instance();

		// Load plugin's config file
		$this->load_config();

		// Set identities_level for operations of this plugin
		$ilevel = $rcmail->config->get('squirrelmail_identities_level');
		if ($ilevel === null)
			$ilevel = $rcmail->config->get('identities_level', 0);

		$this->identities_level = intval($ilevel);
	}

	private function read_squirrel_prefs($uname)
	{
		$rcmail = rcmail::get_instance();

		/**** File based backend ****/
		if ($rcmail->config->get('squirrelmail_driver') == 'file' && ($srcdir = $rcmail->config->get('squirrelmail_data_dir'))) {
			if (($hash_level = $rcmail->config->get('squirrelmail_data_dir_hash_level')) > 0) 
				$srcdir = slashify($srcdir).chunk_split(substr(base_convert(crc32($uname), 10, 16), 0, $hash_level), 1, '/');
			$prefsfile = slashify($srcdir) . $uname . '.pref';
			$abookfile = slashify($srcdir) . $uname . '.abook';
			$sigfile = slashify($srcdir) . $uname . '.sig';
			$sigbase = slashify($srcdir) . $uname . '.si';

			if (is_readable($prefsfile)) {
				$this->prefs = array();
				foreach (file($prefsfile) as $line) {
					list($key, $value) = explode('=', $line);
					$this->prefs[$key] = utf8_encode(rtrim($value));
				}

				// also read signature file if exists
				if (is_readable($sigfile)) {
					$this->prefs['___signature___'] = utf8_encode(file_get_contents($sigfile));
				}

				if (isset($this->prefs['identities']) && $this->prefs['identities'] > 1) {
					for ($i=1; $i < $this->prefs['identities']; $i++) {
						// read signature file if exists
						if (is_readable($sigbase.$i)) {
							$this->prefs['___sig'.$i.'___'] = utf8_encode(file_get_contents($sigbase.$i));
						}
					}
				}

 				// Get domain from username
				list($uuser,$udomain)=explode('@',$uname);

				// parse addres book file
				if (filesize($abookfile)) {
					foreach(file($abookfile) as $line) {
						list($rec['name'], $rec['firstname'], $rec['surname'], $rec['email']) = explode('|', utf8_encode(rtrim($line)));
						if(preg_match('/,/',$rec['email']))
						{	
							$email_arr=explode(',',$rec['email']);
							$group_name=trim($rec['name']);
						
							foreach($email_arr as $value)
							{
								$value=trim($value);

								if ($value)
								{
									/* If no domain part mentioned then use default user domain */
									if(! preg_match('/@/',$value))
										$value .= '@'.$udomain;

									list($value_name)=explode('@',$value);
									$rec['name']=$value_name;
									$rec['firstname']=$value_name;
									$rec['surname']='';
									$rec['email']=$value;
									$this->abook[] = $rec;
								}

							$rec['addressgroup'] = $group_name;
                                			$rec['email'] = trim($value);
                                			$this->abook_group[] = $rec;
							}

						}
						else
						{
						if ($rec['name'] && $rec['email'])
							
							/* If no domain part mentioned then use default user domain */
							if(! preg_match('/@/',$rec['email']))
								$rec['email'] .= '@'.$udomain;

							$this->abook[] = $rec;
						}
					}
				}
			}
		} 
		/**** Database backend ****/
		else if ($rcmail->config->get('squirrelmail_driver') == 'sql') { 
			$this->prefs = array();

			/* connect to squirrelmail database */
			$db = new rcube_mdb2($rcmail->config->get('squirrelmail_dsn'));
			$db->db_connect('r'); // connect in read mode

			// $db->set_debug(true);

			/* retrieve prefs */
			$userprefs_table = $rcmail->config->get('squirrelmail_userprefs_table');
			$address_table = $rcmail->config->get('squirrelmail_address_table');
			$addressgroup_table = $rcmail->config->get('squirrelmail_abook_group_table');
			$db_charset = $rcmail->config->get('squirrelmail_db_charset');

			if ($db_charset)
				$db->query('SET NAMES '.$db_charset);

			$sql_result = $db->query('SELECT * FROM '.$userprefs_table.' WHERE user=?', $uname); // ? is replaced with emailaddress

			while ($sql_array = $db->fetch_assoc($sql_result) ) { // fetch one row from result
				$this->prefs[$sql_array['prefkey']] = rcube_charset_convert(rtrim($sql_array['prefval']), $db_charset);
			}

			/* retrieve address table data */
			$sql_result = $db->query('SELECT * FROM '.$address_table.' WHERE owner=?', $uname); // ? is replaced with emailaddress

			// parse addres book
			while ($sql_array = $db->fetch_assoc($sql_result) ) { // fetch one row from result
				
				if ( $rcmail->config->get('squirrelmail_pretty_contact_name') ) {
					// possibilities where limited in the nickname
					if ( isset($sql_array['firstname']) && isset($sql_array['lastname']) ) {
						$rec['name'] = rcube_charset_convert( rtrim($sql_array['firstname'])
						   . ' ' . rtrim($sql_array['lastname']), $db_charset ); // contruct new display name
						   } elseif ( isset($sql_array['firstname']) ) {
						$rec['name'] = rcube_charset_convert(rtrim($sql_array['firstname']), $db_charset);
					} elseif ( isset($sql_array['lastname']) )  {
						$rec['name'] = rcube_charset_convert(rtrim($sql_array['lastname']), $db_charset);
					} else {
						$rec['name'] = rcube_charset_convert(rtrim($sql_array['nickname']), $db_charset);
					}
					// try to make "better looking"
					if ( preg_match('/[\w|-|_|+]\.[\w|-|_|+]/', $rec['name']) ) $rec['name'] = str_replace(".", " ", $rec['name']);
					if ( preg_match('/[\w|-|+]\_[\w|-|+]/', $rec['name']) ) $rec['name'] = str_replace("_", " ", $rec['name']);
					if ( strtolower($rec['name']) == $rec['name'] ) $rec['name'] = ucwords($rec['name']);

				} else {
					$rec['name'] = rcube_charset_convert(rtrim($sql_array['nickname']), $db_charset);
				}				
				$rec['firstname'] = rcube_charset_convert(rtrim($sql_array['firstname']), $db_charset);
				$rec['surname']   = rcube_charset_convert(rtrim($sql_array['lastname']), $db_charset);
				$rec['email']     = rcube_charset_convert(rtrim($sql_array['email']), $db_charset);
				$rec['tags']      = rcube_charset_convert(rtrim($sql_array['label']), $db_charset);

				if ($rec['name'] && $rec['email'])
					$this->abook[] = $rec;
			}

			/* retrieve address group table data */
			$sql_result = $db->query('SELECT t1.addressgroup, t2.email FROM '.$addressgroup_table.' AS t1 JOIN '.$address_table.' AS t2 ON t1.nickname = t2.nickname AND t1.owner = t2.owner WHERE t1.owner=? ORDER BY t1.addressgroup', $uname); // ? is replaced with user
			$this_group="";

			// parse address book groups
			while ($sql_array = $db->fetch_assoc($sql_result) ) { // fetch one row from result

				$rec['addressgroup'] = rcube_charset_convert(rtrim($sql_array['addressgroup']), $db_charset);
				$rec['email'] = rcube_charset_convert(rtrim($sql_array['email']), $db_charset);

				$this->abook_group[] = $rec;
				
			}

		} // end if 'sql'-driver
	}

}
