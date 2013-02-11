<?php

/*
Copyright (c) 2013, yerenkow
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

class nginx_front_plugin {

	var $plugin_name = 'nginx_front_plugin';
	var $class_name = 'nginx_front_plugin';

	// private variables
	var $action = '';

	//* This function is called during ispconfig installation to determine
	//  if a symlink shall be created for this plugin.
	function onInstall() {
		global $conf;
			return true;
	}


	/*
	 	This function is called when the plugin is loaded
	*/

	function onLoad() {
		global $app;

		/*
		Register for the events
		*/
		$app->plugins->registerEvent('web_domain_insert',$this->plugin_name,'update');
		$app->plugins->registerEvent('web_domain_update',$this->plugin_name,'update');
		$app->plugins->registerEvent('web_domain_delete',$this->plugin_name,'delete');

	}

	function update($event_name,$data) {
		global $app, $conf;

		if($this->action != 'insert') $this->action = 'update';

		if($data['new']['type'] != 'vhost' && $data['new']['parent_domain_id'] > 0) {

			$old_parent_domain_id = intval($data['old']['parent_domain_id']);
			$new_parent_domain_id = intval($data['new']['parent_domain_id']);

			// If the parent_domain_id has been changed, we will have to update the old site as well.
			if($this->action == 'update' && $old_parent_domain_id != $new_parent_domain_id) {
				$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$old_parent_domain_id." AND active = 'y'");
				$data['new'] = $tmp;
				$data['old'] = $tmp;
				$this->action = 'update';
				$this->update($event_name,$data);
			}

			// This is not a vhost, so we need to update the parent record instead.
			$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$new_parent_domain_id." AND active = 'y'");
			$data['new'] = $tmp;
			$data['old'] = $tmp;
			$this->action = 'update';
		}

		// load the server configuration options
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		// Get the client ID
		$client = $app->dbmaster->queryOneRecord('SELECT client_id FROM sys_group WHERE sys_group.groupid = '.intval($data['new']['sys_groupid']));
		$client_id = intval($client['client_id']);
		unset($client);

		//* Create the vhost config file
		$app->load('tpl');

		$tpl = new tpl();
		$tpl->newTemplate('nginx_front.conf.master');

		$vhost_data = $data['new'];
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/web';
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/web';
		$vhost_data['web_basedir'] = $web_config['website_basedir'];

		// IPv6
		if($data['new']['ipv6_address'] != '') $tpl->setVar('ipv6_enabled', 1);

		// Custom nginx directives
		$final_nginx_directives = array();
		$nginx_directives = $data['new']['nginx_directives'];
		// Make sure we only have Unix linebreaks
		$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
		$nginx_directives = str_replace("\r", "\n", $nginx_directives);
		$nginx_directive_lines = explode("\n", $nginx_directives);
		if(is_array($nginx_directive_lines) && !empty($nginx_directive_lines)){
			foreach($nginx_directive_lines as $nginx_directive_line){
				$final_nginx_directives[] = array('nginx_directive' => $nginx_directive_line);
			}
		}
		$tpl->setLoop('nginx_directives', $final_nginx_directives);

		// Check if a SSL cert exists
		$ssl_dir = $data['new']['document_root'].'/ssl';
		$domain = $data['new']['ssl_domain'];
		$key_file = $ssl_dir.'/'.$domain.'.key';
		$crt_file = $ssl_dir.'/'.$domain.'.crt';

		if($domain!='' && $data['new']['ssl'] == 'y' && @is_file($crt_file) && @is_file($key_file) && (@filesize($crt_file)>0)  && (@filesize($key_file)>0)) {
			$vhost_data['ssl_enabled'] = 1;
			$app->log('Enable SSL for: '.$domain,LOGLEVEL_DEBUG);
		} else {
			$vhost_data['ssl_enabled'] = 0;
			$app->log('SSL Disabled. '.$domain,LOGLEVEL_DEBUG);
		}

		// Set SEO Redirect
		if($data['new']['seo_redirect'] != '' && ($data['new']['subdomain'] == 'www' || $data['new']['subdomain'] == '*')){
			$vhost_data['seo_redirect_enabled'] = 1;
			if($data['new']['seo_redirect'] == 'non_www_to_www'){
				$vhost_data['seo_redirect_origin_domain'] = $data['new']['domain'];
				$vhost_data['seo_redirect_target_domain'] = 'www.'.$data['new']['domain'];
			}
			if($data['new']['seo_redirect'] == 'www_to_non_www'){
				$vhost_data['seo_redirect_origin_domain'] = 'www.'.$data['new']['domain'];
				$vhost_data['seo_redirect_target_domain'] = $data['new']['domain'];
			}
		} else {
			$vhost_data['seo_redirect_enabled'] = 0;
		}

		$tpl->setVar($vhost_data);

		// Rewrite rules
		$rewrite_rules = array();
		if($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '') {
			if(substr($data['new']['redirect_path'],-1) != '/') $data['new']['redirect_path'] .= '/';
			if(substr($data['new']['redirect_path'],0,8) == '[scheme]') $data['new']['redirect_path'] = '$scheme'.substr($data['new']['redirect_path'],8);
			/* Disabled path extension
			if($data['new']['redirect_type'] == 'no' && substr($data['new']['redirect_path'],0,4) != 'http') {
				$data['new']['redirect_path'] = $data['new']['document_root'].'/web'.realpath($data['new']['redirect_path']).'/';
			}
			*/

			switch($data['new']['subdomain']) {
				case 'www':
					$rewrite_rules[] = array(	'rewrite_domain' 	=> '^'.$data['new']['domain'],
					'rewrite_type' 		=> ($data['new']['redirect_type'] == 'no')?'':$data['new']['redirect_type'],
					'rewrite_target' 	=> $data['new']['redirect_path']);
					$rewrite_rules[] = array(	'rewrite_domain' 	=> '^www.'.$data['new']['domain'],
							'rewrite_type' 		=> ($data['new']['redirect_type'] == 'no')?'':$data['new']['redirect_type'],
							'rewrite_target' 	=> $data['new']['redirect_path']);
					break;
				case '*':
					$rewrite_rules[] = array(	'rewrite_domain' 	=> $data['new']['domain'],
						'rewrite_type' 		=> ($data['new']['redirect_type'] == 'no')?'':$data['new']['redirect_type'],
						'rewrite_target' 	=> $data['new']['redirect_path']);
					break;
				default:
					$rewrite_rules[] = array(	'rewrite_domain' 	=> '^'.$data['new']['domain'],
					'rewrite_type' 		=> ($data['new']['redirect_type'] == 'no')?'':$data['new']['redirect_type'],
					'rewrite_target' 	=> $data['new']['redirect_path']);
			}
		}

		// get alias domains (co-domains and subdomains)
		$aliases = $app->db->queryAllRecords('SELECT * FROM web_domain WHERE parent_domain_id = '.$data['new']['domain_id']." AND active = 'y'");
		$server_alias = array();
		switch($data['new']['subdomain']) {
			case 'www':
				$server_alias[] .= 'www.'.$data['new']['domain'].' ';
				break;
			case '*':
				$server_alias[] .= '*.'.$data['new']['domain'].' ';
				break;
		}
		if(is_array($aliases)) {
			foreach($aliases as $alias) {
				switch($alias['subdomain']) {
					case 'www':
						$server_alias[] .= 'www.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
					case '*':
						$server_alias[] .= '*.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
					default:
						$server_alias[] .= $alias['domain'].' ';
						break;
				}
				$app->log('Add server alias: '.$alias['domain'],LOGLEVEL_DEBUG);
				// Rewriting
				if($alias['redirect_type'] != '' && $alias['redirect_path'] != '') {
					if(substr($alias['redirect_path'],-1) != '/') $alias['redirect_path'] .= '/';
					if(substr($alias['redirect_path'],0,8) == '[scheme]') $alias['redirect_path'] = '$scheme'.substr($alias['redirect_path'],8);

					/* Disabled the path extension
					if($data['new']['redirect_type'] == 'no' && substr($data['new']['redirect_path'],0,4) != 'http') {
						$data['new']['redirect_path'] = $data['new']['document_root'].'/web'.realpath($data['new']['redirect_path']).'/';
					}
					*/

					switch($alias['subdomain']) {
						case 'www':
							$rewrite_rules[] = array(	'rewrite_domain' 	=> '^'.$alias['domain'],
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no')?'':$alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path']);
							$rewrite_rules[] = array(	'rewrite_domain' 	=> '^www.'.$alias['domain'],
									'rewrite_type' 		=> ($alias['redirect_type'] == 'no')?'':$alias['redirect_type'],
									'rewrite_target' 	=> $alias['redirect_path']);
							break;
						case '*':
							$rewrite_rules[] = array(	'rewrite_domain' 	=> $alias['domain'],
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no')?'':$alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path']);
							break;
						default:
							$rewrite_rules[] = array(	'rewrite_domain' 	=> '^'.$alias['domain'],
							'rewrite_type' 		=> ($alias['redirect_type'] == 'no')?'':$alias['redirect_type'],
							'rewrite_target' 	=> $alias['redirect_path']);
					}
				}
			}
		}

		//* If we have some alias records
		if(count($server_alias) > 0) {
			$server_alias_str = '';
			$n = 0;

			foreach($server_alias as $tmp_alias) {
				$server_alias_str .= $tmp_alias;
			}
			unset($tmp_alias);

			$tpl->setVar('alias',trim($server_alias_str));
		} else {
			$tpl->setVar('alias','');
		}

		if(count($rewrite_rules) > 0) {
			$tpl->setLoop('redirects',$rewrite_rules);
		}

        // I promise, I\ll find where that config file is ;)
        $web_config['nginx_front_vhost_conf_dir'] = "/etc/nginx/front/";

        //remove old if any
        if($data['old']['domain'] != '' ) {
            $vhost_file = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['old']['domain'].'.conf');
            unlink($vhost_file);
            $vhost_file_disabled = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['old']['domain'].'.disabled');
            unlink($vhost_file_disabled);
        }

        //save new config, if any
        if($data['new']['domain'] != '' ) {
            $vhost_file = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['new']['domain'].'.conf');
            unlink($vhost_file);
            $vhost_file_disabled = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['new']['domain'].'.disabled');
            unlink($vhost_file_disabled);


            if($data['new']['active'] == 'n') {
                $vhost_file = $vhost_file_disabled;
            }

            //* Write vhost file
            file_put_contents($vhost_file,$tpl->grab());
            $app->log('Writing the vhost file: '.$vhost_file,LOGLEVEL_DEBUG);
            unset($tpl);
        }

//        $app->services->restartServiceDelayed('nginx','reload');
	}

	function delete($event_name,$data) {
		global $app, $conf;

		// load the server configuration options
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

        // I promise, I\ll find where that config file is ;)
        $web_config['nginx_front_vhost_conf_dir'] = "/etc/nginx/front/";

		if($data['old']['type'] != 'vhost' && $data['old']['parent_domain_id'] > 0) {
			//* This is a alias domain or subdomain, so we have to update the website instead
			$parent_domain_id = intval($data['old']['parent_domain_id']);
			$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$parent_domain_id." AND active = 'y'");
			$data['new'] = $tmp;
			$data['old'] = $tmp;
			$this->action = 'update';
			// just run the update function
			$this->update($event_name,$data);

		} else {
			//* This is a website
			// Deleting the vhost file, symlink and the data directory
            $vhost_file = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['old']['domain'].'.conf');
            unlink($vhost_file);
            $vhost_file_disabled = escapeshellcmd($web_config['nginx_front_vhost_conf_dir'].'/'.$data['old']['domain'].'.disabled');
            unlink($vhost_file_disabled);
			$app->log('Removing vhost file: '.$vhost_file,LOGLEVEL_DEBUG);

//			$app->services->restartServiceDelayed('nginx','reload');

		}
	}

	//* This function is called when a IP on the server is inserted, updated or deleted
	function server_ip($event_name,$data) {
		return;
	}
} // end class

?>