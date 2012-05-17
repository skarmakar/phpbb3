<?php
/**
*
* @package ucp
* @version $Id: ucp_register.php 8782 2008-08-23 17:20:55Z acydburn $
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* ucp_register
* Board registration after getting info from rails website
* @package ucp
*/
class ucp_sgregister
{
	var $u_action;

	function main($id, $mode)
	{
		global $config, $db, $user, $auth, $template, $phpbb_root_path, $phpEx;

		include($phpbb_root_path . 'includes/functions_profile_fields.' . $phpEx);

		$confirm_id		= request_var('confirm_id', '');
		$coppa			= (isset($_REQUEST['coppa'])) ? ((!empty($_REQUEST['coppa'])) ? 1 : 0) : false;
		$agreed			= (!empty($_POST['agreed'])) ? 1 : 0;
		$submit			= true;
		$change_lang	= request_var('change_lang', '');
		$user_lang		= request_var('lang', $user->lang_name);


		$cp = new custom_profile();

		$error = $cp_data = $cp_error = array();

    // get json data from rails website
    $json_url = 'http://localhost:3000/users/' . request_var('access_code', '', true) . '/info.json';
    $result = file_get_contents($json_url);

    // decode json
    $json_a = json_decode($result, true);
    if(empty($json_a))
    {
      trigger_error("Invalid access code");
    }

    $pw = $json_a[username] . $json_a[uid];
		$data = array(
			'username'			=> utf8_normalize_nfc($json_a[username]),
			'new_password'		=> $pw,
			'password_confirm'	=> $pw,
			'email'				=> strtolower($json_a[email]),
			'email_confirm'		=> strtolower($json_a[email]),
			'confirm_code'		=> request_var('confirm_code', ''),
			'lang'				=> basename(request_var('lang', $user->lang_name)),
			'tz'				=> request_var('tz', (float) $timezone),
      'sg_user_id' => $json_a[uid]
		);

    // Which group by default?
    $group_name = $json_a[admin] ? 'ADMINISTRATORS' : 'REGISTERED';
    $sql = 'SELECT group_id
					FROM ' . GROUPS_TABLE . "
					WHERE group_name = '" . $db->sql_escape($group_name) . "'
						AND group_type = " . GROUP_SPECIAL;
    $result = $db->sql_query($sql);
    $row = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
    $group_id = $row['group_id'];

    $user_type = $json_a[admin] ? USER_FOUNDER : USER_NORMAL;

    // check if user exists having the username, if so, just login, else create user and login
    $sql = 'SELECT user_id
				FROM ' . USERS_TABLE . '
				WHERE ' . $db->sql_in_set('sg_user_id', $data['sg_user_id']);
    $result = $db->sql_query($sql);

    if($userdata = $db->sql_fetchrow($result))
    {
      $user_id = $userdata['user_id'];
      // update email, user_type, group
			$sql_ary = array(
                       'user_email'		=> $data['email'],
                       'user_type' => $user_type,
                       'group_id'				=> (int) $group_id,
			);

			$sql = 'UPDATE ' . USERS_TABLE . '
				SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE user_id = ' . $user_id;
			$db->sql_query($sql);
    }
    else
    {
      // Check and initialize some variables if needed
      $server_url = generate_board_url();
      $user_actkey = '';
      $user_inactive_reason = 0;
      $user_inactive_time = 0;

      $user_row = array(
                        'username'				=> $data['username'],
                        'user_password'			=> phpbb_hash($data['new_password']),
                        'user_email'			=> $data['email'],
                        'group_id'				=> (int) $group_id,
                        'user_timezone'			=> (float) $data['tz'],
                        'user_dst'				=> $is_dst,
                        'user_lang'				=> $data['lang'],
                        'user_type'				=> $user_type,
                        'user_actkey'			=> $user_actkey,
                        'user_ip'				=> $user->ip,
                        'user_regdate'			=> time(),
                        'user_inactive_reason'	=> $user_inactive_reason,
                        'user_inactive_time'	=> $user_inactive_time,
                        'sg_user_id' => $data['sg_user_id']
                        );

      // Register user...
      $user_id = user_add($user_row, $cp_data);
    }

    // This should not happen, because the required variables are listed above...
    if ($user_id === false)
      {
        trigger_error('NO_USER', E_USER_ERROR);
      }
    else
      {
        // autologin user
        $result = $auth->login($data['username'], $data['new_password'], false, true, false);
        if ($result['status'] == LOGIN_SUCCESS)
          {
            $redirect = request_var('redirect', "{$phpbb_root_path}index.$phpEx");
            $message = ($l_success) ? $l_success : $user->lang['LOGIN_REDIRECT'];
            $l_redirect = ($admin) ? $user->lang['PROCEED_TO_ACP'] : (($redirect === "{$phpbb_root_path}index.$phpEx" || $redirect === "index.$phpEx") ? $user->lang['RETURN_INDEX'] : $user->lang['RETURN_PAGE']);

            // append/replace SID (may change during the session for AOL users)
            $redirect = reapply_sid($redirect);

            // Special case... the user is effectively banned, but we allow founders to login
            if (defined('IN_CHECK_BAN') && $result['user_row']['user_type'] != USER_FOUNDER)
              {
                return;
              }

            $redirect = meta_refresh(3, $redirect);
            trigger_error($message . '<br /><br />' . sprintf($l_redirect, '<a href="' . $redirect . '">', '</a>'));
          }
      }

		//
		$user->profile_fields = array();

		// Generate profile fields -> Template Block Variable profile_fields
		$cp->generate_profile_fields('register', $user->get_iso_lang_id());

		//
		$this->tpl_name = 'ucp_sgregister';
		$this->page_title = 'UCP_REGISTRATION';
	}
}

?>
