<?php
class Message_manager_model extends CI_Model
{	
	private $message_user_access_table_name = "message_user_access";	
	private $message_info_table_name 		 = "message_info";
	private $message_participant_table_name = "message_participant";
	private $message_thread_table_name		 = "message_thread";
	
	private $date_time_max="9999-12-31 23:59:59";

	//don't use previously used ids (indexes), just increase and use
	private $departments=array(
		1=>"customers"
		,2=>"agents"
		,3=>"management"
		);

	public function __construct()
	{
		parent::__construct();
		
		return;
	}

	public function install()
	{
		$tbl_name=$this->db->dbprefix($this->message_user_access_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`mu_user_id` INT NOT NULL
				,`mu_departments` BIGINT DEFAULT 0
				,`mu_verifier` TINYINT NOT NULL DEFAULT 0 
				,`mu_supervisor` TINYINT NOT NULL DEFAULT 0
				,PRIMARY KEY (mu_user_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$tbl_name=$this->db->dbprefix($this->message_info_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`mi_message_id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL
				,`mi_sender_type` ENUM ('customer','department','user')
				,`mi_sender_id` BIGINT UNSIGNED
				,`mi_receiver_type` ENUM ('customer','department','user')
				,`mi_receiver_id` BIGINT UNSIGNED
				,`mi_last_activity` DATETIME
				,`mi_subject` VARCHAR(200)
				,`mi_complete` BIT(1) DEFAULT 0
				,`mi_active` BIT(1) DEFAULT 1
				,PRIMARY KEY (`mi_message_id`)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$tbl_name=$this->db->dbprefix($this->message_participant_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`mp_message_id` BIGINT UNSIGNED  NOT NULL
				,`mp_participant_type` ENUM ('customer','department','user')
				,`mp_participant_id` BIGINT
				,PRIMARY KEY (`mp_message_id`,`mp_participant_type`,`mp_participant_id`)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$tbl_name=$this->db->dbprefix($this->message_thread_table_name);
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`mt_thread_id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL
				,`mt_message_id` BIGINT UNSIGNED  NOT NULL
				,`mt_sender_type` ENUM ('customer','department','user')
				,`mt_sender_id` BIGINT
				,`mt_content` TEXT
				,`mt_timestamp` DATETIME
				,`mt_verifier_id` BIGINT DEFAULT 0
				,PRIMARY KEY (`mt_thread_id`)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$this->module_manager_model->add_module("message","message_manager");
		$this->module_manager_model->add_module_names_from_lang_file("message");

		$this->module_manager_model->add_module("message_access","");
		$this->module_manager_model->add_module_names_from_lang_file("message_access");
		
		return;
	}

	public function uninstall()
	{

		return;
	}

	public function get_dashboard_info()
	{
		return "";
		$CI=& get_instance();
		$lang=$CI->language->get();
		$CI->lang->load('ae_module',$lang);		
		
		$data=array();
		$data['modules']=$this->get_all_modules_info($lang);
		$data['total_text']=$CI->lang->line("total");
		
		$CI->load->library('parser');
		$ret=$CI->parser->parse($CI->get_admin_view_file("module_dashboard"),$data,TRUE);
		
		return $ret;		
	}
	
	public function get_sidebar_text()
	{
		//return " (12) ";
	}

	public function get_departments()
	{
		return $this->departments;
	}

	public function get_user_access($user_id)
	{
		$result=$this->db
			->get_where($this->message_user_access_table_name,array("mu_user_id"=>$user_id))
			->row_array();

		$ret=array("verifier"=>0,"supervisor"=>0);
		$deps=0;
		if($result)
		{
			$ret['verifier']=$result['mu_verifier'];
			$ret['supervisor']=$result['mu_supervisor'];
			$deps=$result['mu_departments'];
		}
		
		$departments=array();
		foreach($this->departments as $dep_index=>$dep_name)
			if($deps & (1<<$dep_index))
				$departments[$dep_name]=$dep_index;
			else
				$departments[$dep_name]=0;

		$ret['departments']=$departments;

		return $ret;
	}

	public function set_user_access($user_id,$props)
	{
		$deps=0;
		foreach($this->departments as $dep_index=>$dep_name)
			if($props['departments'][$dep_name])
				$deps+=(1<<$dep_index);

		$rep=array(
			"mu_user_id"=>$user_id
			,"mu_verifier"=>(int)($props['verifier']==1)
			,"mu_supervisor"=>(int)($props['supervisor']==1)
			,"mu_departments"=>$deps
		);

		$this->db->replace($this->message_user_access_table_name, $rep);

		foreach($this->departments as $dep_index=>$dep_name)
			$rep['department_'.$dep_name]=(int)$props['departments'][$dep_name];

		$this->log_manager_model->info("MESSAGE_ACCESS_SET",$rep);

		return;
	}

	public function get_operations_access()
	{
		$user=$this->user_manager_model->get_user_info();
		$user_id=$user->get_id();

		$ret=array();
		
		$access=$this->get_user_access($user_id);
		$ret['users']=$access['supervisor'];		
		$ret['verifier']=$access['verifier'];
		$ret['customers']=$this->access_manager_model->check_access("customer",$user);
		
		$ret['departments']=array();
		if($ret['customers'])
			foreach($access['departments'] as $name => $id)
				$ret['departments'][$name]=$id;

		return $ret;
	}

	public function get_admin_message($message_id,$access)
	{
		$result=$this->get_user_access_to_message($message_id,$access);

		bprint_r($result);
		return;
		$messages=$this->db
			->select(
				$this->message_table_name.".* 
				, sender_user.user_code as suc, sender_user.user_name as sun
				, sender_customer.customer_name as scn
				, receiver_user.user_code as ruc, receiver_user.user_name as run
				, receiver_customer.customer_name as rcn
				, verifier_user.user_code as vuc, verifier_user.user_name as vun
				")
			->from($this->message_table_name)
			->join("user as sender_user","message_sender_id = sender_user.user_id","left")
			->join("customer as sender_customer","message_sender_id = sender_customer.customer_id","left")
			->join("user as receiver_user","message_receiver_id = receiver_user.user_id","left")
			->join("customer as receiver_customer","message_receiver_id = receiver_customer.customer_id","left")
			->join("user as verifier_user","message_verifier_id = verifier_user.user_id","left")
			->where("message_parent_id",$parent_id)
			->order_by("message_id ASC")
			->get()
			->result_array();

	
		if($viewer_type === "user")
		{
	
			if($st==="user" && $rt==="user")
			{
				if($oa['users'] || ($si==$viewer_id) || ($ri==$viewer_id))
					$has_access=TRUE;

				if(($si==$viewer_id) || ($ri==$viewer_id))
				{
					$can_reply=TRUE;
					$can_forward=TRUE;
				}
			}

			if(($st==="customer")&&($rt==="customer"))
				if($oa['customers'])
					$has_access=TRUE;

			if(($st==="customer")&&($rt==="department"))
				if($deps[$ri])
				{
					$has_access=TRUE;
					$can_reply=TRUE;
					$can_forward=TRUE;
				}

			if(($st==="department")&&($rt==="customer"))
				if($deps[$si])
				{
					$has_access=TRUE;
					$can_reply=TRUE;
					$can_forward=TRUE;
				}

			if(!$has_access)
				$messages=NULL;
			else
			{
				$reply_forward['can_reply']=$can_reply;
				$reply_forward['can_forward']=$can_forward;
			}
		}

		return array("messages"=>&$messages,"reply_forward"=>$reply_forward);		
	}

	private function get_user_access_to_message($message_id,$access)
	{
		if($access['type'] !=="user")
			return NULL;

		$op_access=$access['op_access'];
		$user_id=$access['id'];
		$user_deps=$access['department_ids'];
		$all_departemnts=$this->get_departments();

		$results=$this->db
			->select($this->message_info_table_name.".*")
			->from($this->message_info_table_name)
			->select($this->message_participant_table_name.".*")
			->join($this->message_participant_table_name,"mi_message_id = mp_message_id","LEFT")
			->select("user.user_name, user.user_code")
			->join("user","mp_participant_id = user_id","LEFT")
			->where("mi_message_id",$message_id)
			->get()
			->result_array();

		if(!$results)
			return NULL;

		$has_access=FALSE;

		$st=$results[0]['mi_sender_type'];
		$rt=$results[0]['mi_receiver_type'];
		$si=$results[0]['mi_sender_id'];
		$ri=$results[0]['mi_receiver_id'];

		if($st==="user" && $rt==="user")
			if($op_access['users'] || ($si==$user_id) || ($ri==$user_id))
				$has_access=TRUE;

		if(($st==="customer")&&($rt==="customer"))
			if($op_access['customers'])
				$has_access=TRUE;

		if(($st==="customer")&&($rt==="department"))
			if(in_array($ri,$user_deps))
				$has_access=TRUE;

		if(($st==="department")&&($rt==="customer"))
			if(in_array($si,$user_deps))
				$has_access=TRUE;
		
		$access_users=array();
		$access_departments=array();
		
		foreach($results as $row)
			if($row['mp_participant_type']==="department")
			{
				$dep_id=$row['mp_participant_id'];
				$access_departments[$dep_id]=$all_departemnts[$dep_id];
				if(!$has_access && in_array($dep_id,$user_deps))
					$has_access=TRUE;
			}
			else
			{
				$puser_id=$row['mp_participant_id'];
				$access_users[$puser_id]=$row['user_code']." - ".$row['user_name'];
				if($puser_id === $user_id)
					$has_access=TRUE;
			}

		if(!$has_access)
			return NULL;
		
		return array(
			"has_access" 	=> TRUE
			,"departments"	=>$access_departments
			,"users"			=>$access_users
		);
	}

	public function get_total_messages($filters,$access)
	{
		$this->db->select("COUNT(*) as count");
		$this->db->from($this->message_info_table_name)
			->join("user as sender_user","mi_sender_id = sender_user.user_id","left")
			->join("customer as sender_customer","mi_sender_id = sender_customer.customer_id","left")
			->join("user as receiver_user","mi_receiver_id = receiver_user.user_id","left")
			->join("customer as receiver_customer","mi_receiver_id = receiver_customer.customer_id","left")
			;

		$ttbl=$this->db->dbprefix($this->message_thread_table_name);
		if(!isset($filerts['verified']))
			$this->db->join(
				"(SELECT * from $ttbl 
						INNER JOIN (
							SELECT max(mt_thread_id) as max FROM $ttbl 
								GROUP BY mt_message_id
							) AS mtb ON mtb.max = mt_thread_id
				) as mt"
				,"mi_message_id = mt_message_id","INNER");
		else
			$this->db->join(
				"(SELECT * from $ttbl 
						INNER JOIN (
							SELECT max(mt_thread_id) as max FROM $ttbl 
								WHERE mt_sender_type = 'customer'
								GROUP BY mt_message_id 
							) AS mtb ON mtb.max = mt_thread_id
				) as mt"
				,"mi_message_id = mt_message_id","INNER");

		if(isset($access['type']) && ($access['type']==='user') && isset($access['id']))
			$this->db->join(
				$this->message_participant_table_name." as pu"
				,"
					mi_message_id = pu.mp_message_id 
					AND pu.mp_participant_type = 'user'
					AND pu.mp_participant_id = ".$access['id']."
				"
				,"LEFT"
			);

		if(isset($access['department_ids']) && $access['department_ids'])
			$this->db->join(
				$this->message_participant_table_name." as pd"
				,"
					mi_message_id = pd.mp_message_id 
					AND pd.mp_participant_type = 'department'
					AND pd.mp_participant_id IN (".implode(",", $access['department_ids']).")
				"
				,"LEFT"
			);

		$this->set_search_where_clause($filters,$access);

		$query=$this->db->get();

		$row=$query->row_array();

		return $row['count'];
	}

	public function get_messages(&$filters,$access)
	{
		$this->db
			->select(
				$this->message_info_table_name.".*,mt.*,
				, sender_user.user_code as suc, sender_user.user_name as sun
				, sender_customer.customer_name as scn
				, receiver_user.user_code as ruc, receiver_user.user_name as run
				, receiver_customer.customer_name as rcn
				")
			->from($this->message_info_table_name)
			->join("user as sender_user","mi_sender_id = sender_user.user_id","LEFT")
			->join("customer as sender_customer","mi_sender_id = sender_customer.customer_id","LEFT")
			->join("user as receiver_user","mi_receiver_id = receiver_user.user_id","LEFT")
			->join("customer as receiver_customer","mi_receiver_id = receiver_customer.customer_id","LEFT")
			;

		$ttbl=$this->db->dbprefix($this->message_thread_table_name);
		if(!isset($filerts['verified']))
			$this->db->join(
				"(SELECT * from $ttbl 
						INNER JOIN (
							SELECT max(mt_thread_id) as max FROM $ttbl 
								GROUP BY mt_message_id
							) AS mtb ON mtb.max = mt_thread_id
				) as mt"
				,"mi_message_id = mt_message_id","INNER");
		else
			$this->db->join(
				"(SELECT * from $ttbl 
						INNER JOIN (
							SELECT max(mt_thread_id) as max FROM $ttbl 
								WHERE mt_sender_type = 'customer'
								GROUP BY mt_message_id 
							) AS mtb ON mtb.max = mt_thread_id
				) as mt"
				,"mi_message_id = mt_message_id","INNER");

		if(isset($access['type']) && ($access['type']==='user') && isset($access['id']))
			$this->db->join(
				$this->message_participant_table_name." as pu"
				,"
					 mi_message_id = pu.mp_message_id 
					AND pu.mp_participant_type = 'user'
					AND pu.mp_participant_id = ".$access['id']."
				"
				,"LEFT"
			);

		if(isset($access['department_ids']) && $access['department_ids'])
			$this->db->join(
				$this->message_participant_table_name." as pd"
				,"
					 mi_message_id = pd.mp_message_id 
					AND pd.mp_participant_type = 'department'
					AND pd.mp_participant_id IN (".implode(",", $access['department_ids']).")
				"
				,"LEFT"
			);

		$this->set_search_where_clause($filters,$access);

		$result=$this->db->get()->result_array();

		return $result;
	}

	private function set_search_where_clause(&$filters,$access)
	{
		if(isset($filters['start_date']))
			$this->db->where("mi_last_activity >=",$filters['start_date']);

		if(isset($filters['end_date']))
			$this->db->where("mi_last_activity <=",$filters['end_date']." 23:59:59");

		if(isset($filters['status']))
		{
			if($filters['status']==="complete")
				$this->db->where("mi_complete",1);
			else
				$this->db->where("mi_complete",0);
		}

		if(isset($filters['verified']))
		{
			$this->db
				->where("mi_sender_type","customer")
				->where("mi_receiver_type","customer");

			if($filters['verified']==="yes")
				$this->db->where("mt_verifier_id !=",0);
			else
				$this->db->where("mt_verifier_id",0);
		}

		$mess_types="0";
		//bprint_r($filters['message_types']);
		//exit(0);
		foreach($filters['message_types'] as $mess)
		{
			$query="1";
			foreach($mess as $field => $value)
			{
				$exfield=explode("_", $field);
				$del=$exfield[sizeof($exfield)-1];

				if($del==="type" || $del==="id" || $del==="code")
					$query.=" && ( ".$field."='".$value."' )";

				if($del==="in" && is_array($value))
				{
					unset($exfield[sizeof($exfield)-1]);
					$field=implode("_", $exfield);
					$value="('".implode("','", $value)."')";

					$query.=" && ( ".$field." in ".$value." )";
				}

				if($del==="name")
				{
					$value=prune_for_like_query($value);
					$query.=" && ( ".$field." like '%".$value."%' )";
				}

				//echo $del."<br>";
			}

			$mess_types.=" || ( ".$query." ) "; 
		}

		if(isset($access['type']) && ($access['type']==='user') && isset($access['id']))
			$mess_types.=" || (!ISNULL(pu.mp_participant_id))";

		if(isset($access['department_ids']) && $access['department_ids'])
			$mess_types.=" || (!ISNULL(pd.mp_participant_id))";		
		//echo $mess_types."<br>";exit();

		$this->db->where((" ( ".$mess_types." )"));

		if(isset($filters['order_by']))
			$this->db->order_by($filter['order_by']);
		else
			$this->db->order_by("mi_last_activity DESC");

		if(isset($filters['start']) && isset($filters['length']))
			$this->db->limit((int)$filters['length'],(int)$filters['start']);

		return;
	}

	public function add_c2d_message(&$props)
	{
		$mess=array(
			"mi_sender_type"		=>"customer"
			,"mi_sender_id"		=>$props['customer_id']
			,"mi_receiver_type"	=>"department"
			,"mi_receiver_id"		=>$props['department']
			,"mi_subject"			=>$props['subject']
		);

		$mid=$this->add_message($mess);
		$mess['message_type']="c2u";
		$mess['message_id']=$mid;
		$mess['departement_name']=$this->get_departments()[$props['department']];
		
		$this->load->model("customer_manager_model");
		$this->customer_manager_model->set_customer_event($props['customer_id'],"has_message");
		$this->customer_manager_model->add_customer_log($props['customer_id'],'MESSAGE_ADD',$mess);

		$thr=array(
			'mt_message_id'	=> $mid
			,'mt_sender_type'	=> "customer"
			,'mt_sender_id'	=> $props['customer_id']
			,'mt_content'		=> $props['content']
		);

		$tid=$this->add_thread($thr);

		$thr['mt_thread_id']=$tid;		
		$this->customer_manager_model->add_customer_log($props['customer_id'],'MESSAGE_THREAD_ADD',$thr);

		return $mid;
	}

	public function verify_c2c_messages($verifier_id,&$v,&$nv)
	{
		$ret=["v"=>0,"nv"=>0];
		if($v)
		{
			$this->db
				->set("mt_verifier_id",$verifier_id)
				->where("mt_sender_type","customer")
				->where("mt_verifier_id",0)
				->where_in("mt_thread_id",$v)
				->update($this->message_thread_table_name);
			
			$ret['v']=$this->db->affected_rows();
		}

		if($nv)
		{
			$this->db
				->set("mt_verifier_id",0)
				->where("mt_sender_type","customer")
				->where("mt_verifier_id !=",0)
				->where_in("mt_thread_id",$nv)
				->update($this->message_thread_table_name);

			$ret['nv']=$this->db->affected_rows();
		}

		$result=array(
			"verifier_id"=>$verifier_id
			,"requested_verified_ids"=>implode(",", $v)
			,"verified_count"=>$ret['v']
			,"requested_not_verified_ids"=>implode(",", $nv)
			,"not_verified_count"=>$ret['nv']
		);
		$this->log_manager_model->info("MESSAGE_VERIFY",$result);

		return $ret;
	}

	private function add_message(&$props)
	{
		$props['mi_last_activity']=get_current_time();

		$this->db->insert($this->message_info_table_name,$props);
		$id=$this->db->insert_id();
		
		$props['mi_message_id']=$id;
		$this->log_manager_model->info("MESSAGE_ADD",$props);

		return $id;
	}

	private function add_thread(&$props)
	{
		$props['mt_timestamp']=get_current_time();

		$this->db->insert($this->message_thread_table_name,$props);
		$id=$this->db->insert_id();

		$props['mt_thread_id']=$id;
		$this->log_manager_model->info("MESSAGE_THREAD_ADD",$props);

		return $id;
	}
}