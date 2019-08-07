<?php
/**
 * 用来处理与工时花费相关的操作
 *
 * @author robertyang
 * @package Model
 */
class Timesheet extends AppModel {
  	var $name = 'Timesheet';
  	var $useTable = 'timesheets';
  	var $uses = array('Story', 'Task') ;
  	var $validate = array(
  		'entity_id' => VALID_NUMBER,
  		'entity_type' => VALID_NOT_EMPTY,
		'timespent' => VALID_NUMBER,
		'spentdate' => VALID_NOT_EMPTY,
	);
	var $no_need_update_effort = false;

	/**
	 * 保存之后的逻辑操作
	 *
	 * @author frankychen
	 * @access public
	 * @return boolean 保存是否成功
	 */
	function afterSave() {
		$data = $this->data;
		if(!$this->update_effort($data)) {
			return false;
		} else {
			return parent::afterSave();
		}
	}
	function update_effort($data = array()) {
		$ret = false;
		if ($this->_need_update_effort($data[$this->name])) {
			$entity_type = $data['Timesheet']['entity_type'];
			$entity_id = $data['Timesheet']['entity_id'];
			$effort_total_data = array();
			if( 'story' == $entity_type ) {
				$story = g('Story', 'Model')->findById( $entity_id, array('effort_completed','exceed', 'effort', 'progress','exceed','remain','parent_id', 'id') );
				if( !empty($story) ) {
					$story['Story']['effort_completed'] = $this->get_totalSpents($entity_type, $entity_id);
					$last_remain = $this->_get_latest_timesheet_remain($entity_type, $entity_id);
					if( false !== $last_remain ) {
						$story['Story']['remain'] = $last_remain;
					}
					$story['Story']['exceed'] = number_format($story['Story']['remain'] + $story['Story']['effort_completed'] - $story['Story']['effort'], 2);

					if( $story['Story']['effort']+$story['Story']['exceed'] == 0 ) {
						$story['Story']['progress'] = 0;
					} else {
						$story['Story']['progress'] = round(($story['Story']['effort_completed']/($story['Story']['effort']+$story['Story']['exceed']) )*100);
					}

					//埋点 add by janeleozhou
					$workspace_id = get_workspace_id_from_long_id( $entity_id );
					if( TEST_EFFORT_WORKSPACE == $workspace_id ) {
						write_effort_log( 'Timesheet::update_effort',
							$workspace_id, $entity_id,
							$story['Story']['parent_id'], 'story',
							[ $story['Story']['effort'], $story['Story']['effort_completed'], $story['Story']['remain'], $story['Story']['exceed'] ] );
					}
					g('Story', 'Model')->save($story);
					if( !empty( $story['Story']['parent_id'] ) ) {
						//跨项目工时parent_id不对，移动完修复parent_id后再处理工时
						if($workspace_id == get_workspace_id_from_long_id($story['Story']['parent_id'])) {
							g('Story', 'Model')->update_ancestors_story_effort( $story['Story']['parent_id'] );
						}
					}
				}
			} else if( 'task' == $entity_type ) {
				g('prong.Task', 'Model')->cacheQueries = false;
				$task = g('prong.Task', 'Model')->findById( $entity_id, array('effort_completed', 'exceed', 'effort', 'progress','exceed', 'remain', 'story_id', 'id', 'status') );
				if( !empty($task) ) {
					$story_id=$task['Task']['story_id'];
					$old_status = $task['Task']['status'];
					$task['Task']['effort_completed'] = $this->get_totalSpents($entity_type, $entity_id);
					$last_remain = $this->_get_latest_timesheet_remain($entity_type, $entity_id);
					if( false !== $last_remain ) {
						$task['Task']['remain'] = $last_remain;
					}
					$task['Task']['exceed'] = number_format($task['Task']['remain'] + $task['Task']['effort_completed'] - $task['Task']['effort'], 2);
					if( ($task['Task']['effort']+$task['Task']['exceed'] == 0) || $task['Task']['effort_completed'] == 0 ) {
						$task['Task']['progress'] = 0;
					} else {
						$task['Task']['progress'] = round(($task['Task']['effort_completed']/($task['Task']['effort']+$task['Task']['exceed']) )*100 );
					}

					$workspace_id = get_workspace_id_from_long_id( $entity_id );
					if( TEST_EFFORT_WORKSPACE == $workspace_id ) {
						write_effort_log( 'Timesheet::update_effort',
							$workspace_id, $entity_id,
							$task['Task']['story_id'], 'task',
							[ $task['Task']['effort'], $task['Task']['effort_completed'],
								$task['Task']['remain'], $task['Task']['exceed'] ] );
					}

					if( $task['Task']['remain'] > 0) {
						$task['Task']['status'] = STATUS_PROGRESSING;
					} else if( $task['Task']['remain'] == 0) {
						$task['Task']['status'] = STATUS_DONE;
					}
					$task['Task']['story_id']=$story_id;
					$changes = array(
										array(
											'field' => 'status',
											'value_before' => $old_status,
											'value_after' => $task['Task']['status'],
										)
								);
					$task_change = array(
											'workspace_id' => $workspace_id,
											'workitem_id' => $entity_id,
											'creator' => current_user('nick', $workspace_id),
											'change_summary' => $old_status,
											'changes' => json_encode($changes),
											'entity_type' => 'task'
								);
					$workitem_change_model = g('WorkitemChange', 'Model');
					$workitem_change_model->terminal = true;
					$workitem_change_model->save($task_change);
					g('prong.Task', 'Model')->save($task);
					//更新任务的父需求以及祖先需求的工时
					g('TaskEffortManager', 'Model')->update_p_story_effort( $task['Task'] );

				}
			}
			return true;
		}
		return false;
	}

	/**
	 * 得到业务对象最新的剩余时间
	 *
	 * @author joeyue
	 * @param string $entity_type 对象类型
	 * @param int $entity_id 对象id
	 * @return float
	 */
	function _get_latest_timesheet_remain($entity_type, $entity_id) {
		$rand = microtime(true);
		$ret = $this->findAll(array('entity_id' => $entity_id, 'entity_type' => $entity_type, "$rand" => "$rand"), 'timeremain', 'spentdate desc,modified desc', 1);
		//如果timesheet表有工时，则取工时，否则保持不变
		if (!empty($ret[0]['Timesheet'])) {
			return $ret[0]['Timesheet']['timeremain'];
		} else {
			return false;
		}
	}

	/**
	 * 删除timesheet后级联更新effort
	 *
	 * @author markguo
	 *
	 */
	function delete($id) {
		$time = microtime_float();
		$timesheet_to_delete = $this->find(array('id' => $id, $time => $time));
		if (empty($timesheet_to_delete)) {
			return false;
		}
		if (parent::delete($id)){
			$this->update_timesheets_after_delete($timesheet_to_delete);
			$this->update_effort_after_delete($timesheet_to_delete);
			return true;
		}
		return false;
	}

	function update_timesheets_after_delete($del_timesheet) {
		$this->update_timesheets_after_add_timesheet($del_timesheet['Timesheet']);
	}

	function update_effort_after_delete($del_timesheet) {
		$entity_id = $del_timesheet['Timesheet']['entity_id'];
		$entity_type = $del_timesheet['Timesheet']['entity_type'];
		$workspace_id = $del_timesheet['Timesheet']['workspace_id'];
		if( 'story' == $entity_type ) {
			$story = g('Story', 'Model')->findById( $entity_id, array('effort_completed','exceed', 'effort', 'progress','exceed','remain','parent_id', 'id') );
			if( !empty($story) ) {
				$before_story = $story['Story'];
				//获取完成工时
				$story['Story']['effort_completed'] = $this->get_totalSpents($entity_type, $entity_id);
				$recent_timesheet = $this->get_recentTimesheet($entity_type, $entity_id);
				if (!empty($recent_timesheet)) {
					$story['Story']['remain'] = $recent_timesheet['Timesheet']['timeremain'];
				} else {
					$story['Story']['remain'] = $story['Story']['effort'];
				}

				$story['Story']['exceed'] = $story['Story']['remain'] + $story['Story']['effort_completed'] - $story['Story']['effort'];

				if(($story['Story']['effort']+$story['Story']['exceed']) == 0){
					$story['Story']['progress'] = 0;
				} else {
					$story['Story']['progress'] = round(($story['Story']['effort_completed']/($story['Story']['effort']+$story['Story']['exceed']) )*100);
				}

				g('Story', 'Model')->save($story);

				//记录变更历史
				$field_changes[] = array('field' => "effort_completed", 'value_before' => $before_story['effort_completed'], 'value_after' => $story['Story']['effort_completed'] );
				$field_changes[] = array('field' => "remain", 'value_before' => $before_story['remain'], 'value_after' => $story['Story']['remain']);
				$field_changes[] = array('field' => "exceed", 'value_before' => $before_story['exceed'], 'value_after' => $story['Story']['exceed']);
				$workitem_changes['workitem_id'] = $entity_id;
				$workitem_changes['changes'] = json_encode($field_changes);
				$workitem_changes['creator'] = current_user('nick', $workspace_id);
				$workitem_changes['workspace_id'] = $workspace_id;
				$workitem_changes['entity_type'] = 'Story';
				g('WorkitemChange', 'Model')->save($workitem_changes);

				if( !empty( $story['Story']['parent_id']) ) {
					g('Story', 'Model')->update_ancestors_story_effort( $story['Story']['parent_id'] );
				}
			}
		} else if( 'task' == $entity_type ) {
			$task = g('prong.Task', 'Model')->findById( $entity_id, array('effort_completed', 'exceed', 'effort', 'progress','exceed', 'remain', 'story_id', 'id') );
			if( !empty($task) ) {
				$before_task = $task['Task'];
				$task['Task']['effort_completed'] = $this->get_totalSpents($entity_type, $entity_id);
				$recent_timesheet = $this->get_recentTimesheet($entity_type, $entity_id);
				if (!empty($recent_timesheet)) {
					$task['Task']['remain'] = $recent_timesheet['Timesheet']['timeremain'];
				} else {
					$task['Task']['remain'] = $task['Task']['effort'];
				}
				$task['Task']['exceed'] = $task['Task']['remain'] + $task['Task']['effort_completed'] - $task['Task']['effort'];
				if( ($task['Task']['effort']+$task['Task']['exceed'] == 0) || $task['Task']['effort_completed'] == 0 ) {
					$task['Task']['progress'] = 0;
				} else {
					$task['Task']['progress'] = round(($task['Task']['effort_completed']/($task['Task']['effort']+$task['Task']['exceed']) )*100 );
				}
				g('prong.Task', 'Model')->save($task);

				//记录变更历史
				$field_changes[] = array('field' => "effort_completed", 'value_before' => $before_task['effort_completed'], 'value_after' => $task['Task']['effort_completed'] );
				$field_changes[] = array('field' => "remain", 'value_before' => $before_task['remain'], 'value_after' => $task['Task']['remain']);
				$field_changes[] = array('field' => "exceed", 'value_before' => $before_task['exceed'], 'value_after' => $task['Task']['exceed']);
				$workitem_changes['workitem_id'] = $entity_id;
				$workitem_changes['changes'] = json_encode($field_changes);
				$workitem_changes['creator'] = current_user('nick', $workspace_id);
				$workitem_changes['workspace_id'] = $workspace_id;
				$workitem_changes['entity_type'] = 'Task';
				g('WorkitemChange', 'Model')->save($workitem_changes);

				//更新任务的父需求以及祖先需求的工时
				g('TaskEffortManager', 'Model')->update_p_story_effort( $task['Task'] );
			}
		}
	}


	/**
	 * 获取所有操作过workitem的责任人
	 *
	 * @param string $objecttype 对象类型，如story、task等
	 * @param int $object_id 对象id
	 * @author joeyue
	 * @access public
	 * @return array 责任人数组
	 */
    function getOwners($objecttype, $object_id) {
    	if (!is_numeric($object_id)) return array();
    	$objecttype = MyClean::cleanValue($objecttype);
		$quer_owners = "select owner from timesheets where entity_type = '".$objecttype."' and entity_id = ".$object_id." group by owner";
		$owners_obj = $this->query($quer_owners);
		$owners = array();
		foreach ($owners_obj as $obj) {
			foreach ($obj as $timesheet) {
				foreach ($timesheet as $owner) {
					$owners[] = $owner;
				}
			}
		}
		return $owners;
    }
   /**
    *  获取所有的时间花费
    *
    * when the param owner is not null, you will get the total spenttime that the owner spent on the workitem
    * or else you will get all spent time of all peoplt spent on it.
    *
    * @param string $objecttype 对象类型，如story、task等
    * @param int $object_id 对象id
    * @param string $owner 处理人
    * @author joeyue
    * @access public
    * @return array 时间花费数组
    */
   function get_totalSpents($objecttype, $object_id, $owner = null) {
   		if (!is_numeric($object_id)) return 0;
    	$objecttype = MyClean::cleanValue($objecttype);
   		$rand = microtime(true);
		if ($owner == null) {
			$query_owner_spent_total = "select sum(timespent) as spent_total from timesheets where entity_type = '"
			.$objecttype."'  and entity_id = ".$object_id . " and $rand = $rand";
		} else {
	    	$owner = MyClean::cleanValue($owner);
			$query_owner_spent_total = "select sum(timespent) as spent_total from timesheets where entity_type = '".$objecttype."'  and entity_id = ".$object_id." and owner = '".$owner."'" . " and $rand = $rand";
		}
		$spents = $this->query($query_owner_spent_total);
		if( empty($spents) ) {
			return 0;
		}
		$total = 0;
		foreach ($spents as $obj) {
			foreach ($obj as $time) {
				foreach ($time as $spent) {
					$total += round($spent,2);
				}
			}
		}
		return $total;
   }

   /**
    * 获取最近的剩余工时
    *
    * when the param owner is not null, you will get the recent remain that the owner work on the workitem
    * or else you will get rencent remain.
    *
    * @param string $objecttype 对象类型，如story、task等
    * @param int $object_id 对象id
    * @param string $owner 处理人
    * @author joeyue
    * @access public
    * @return int 剩余工时
    */
   function get_recentRemain($objecttype, $object_id , $owner = null) {
   		if (!is_numeric($object_id)) return 0;
    	$objecttype = MyClean::cleanValue($objecttype);
		if ($owner == null) {
			$query_owner_rencent_remain = "select spentdate,timeremain from timesheets where entity_type = '".$objecttype."'  and entity_id = ".$object_id." order by spentdate desc limit 1";
		} else {
	    	$owner = MyClean::cleanValue($owner);
			$query_owner_rencent_remain = "select spentdate,timeremain from timesheets where entity_type = '".$objecttype."'  and entity_id = ".$object_id." and owner = '".$owner."'"." order by spentdate desc limit 1";
		}
		$remains = $this->query($query_owner_rencent_remain);
		$remain = 0;
		foreach ($remains as $obj) {
			foreach ($obj as $time) {
				foreach ($time as $recent) {
					$remain = $recent;
				}
			}
		}
		return $remain;
   }

   function get_recentTimesheet($objecttype, $object_id , $owner = null) {
		$condition = array();
		$condition['entity_type'] = $objecttype;
		$condition['entity_id'] = $object_id;
		if ($owner != null) {
			$condition['owner'] = $owner;
		}
		$rand = microtime(true);
		$condition["$rand"] = "$rand";
		$timesheet = array();
		$timesheets = $this->findAll($condition, null, 'spentdate desc,created desc,id desc', '1');
		if (!empty($timesheets)) {
			$timesheet = $timesheets[0];
		}
		return $timesheet;
   }

   /**
    * 获取某日期之前的总花费
    *
    * @param string $objecttype 对象类型，如story、task等
    * @param int $object_id 对象id
    * @param string $spent_date 起始日
    * @author joeyue
    * @access public
    * @return int 总花费
    */
   function get_total_spent_before_date($objecttype, $object_id, $spent_date) {
   		if (!is_numeric($object_id)) return 0;
    	$objecttype = MyClean::cleanValue($objecttype);
    	$spent_date = MyClean::cleanValue($spent_date);
    	$rand = microtime(true);
		$sql = "select sum(timespent) as spent_total from timesheets where entity_type = '$objecttype'  and entity_id = $object_id and spentdate < '$spent_date' and $rand";
		$spents = $this->query($sql);
		$total = 0;
			foreach ($spents as $obj) {
				foreach ($obj as $time) {
					foreach ($time as $spent) {
						$total = $spent==null?0:$spent;
					}
				}
			}
		return $total;
   }

   function get_total_spent_on_date($objecttype, $object_id, $spent_date) {
   		$spent_date = date("Y-m-d", strtotime("+1 day", strtotime($spent_date)));
   		return $this->get_total_spent_before_date($objecttype, $object_id, $spent_date);
   }

   function get_total_spent_by_spentdate_createdate($objecttype, $object_id, $spent_date, $createdate=null) {
   		if (!empty($createdate)) {
	   		return $this->get_total_spent_at_spentdate_before_createdate($objecttype, $object_id, $spent_date, $createdate) + $this->get_total_spent_before_date($objecttype, $object_id, $spent_date);
   		}
   		return $this->get_total_spent_before_date($objecttype, $object_id, $spent_date);
   }

   function get_total_spent_at_spentdate_before_createdate($objecttype, $object_id, $spent_date, $createdate) {
   		if (!is_numeric($object_id)) return 0;
   		$rand = microtime(true);
    	$objecttype = MyClean::cleanValue($objecttype);
    	$spent_date = MyClean::cleanValue($spent_date);
    	$createdate = MyClean::cleanValue($createdate);
   		$sql = "select sum(timespent) as spent_total from timesheets where entity_type = '$objecttype'  and entity_id = $object_id and spentdate = '$spent_date' and created < '$createdate' and $rand";
	   	$spent = $this->query($sql);
   		$spent = $spent[0][0]['spent_total'];
   		return $spent;
   }

   /**
    * 获取在某日期的剩余工时
    *
    * @param string $objecttype 对象类型，如story、task等
    * @param int $object_id 对象id
    * @param string $spentdate 条件日期
    * @author joeyue
    * @access public
    * @return int 当日剩余工时
    */
   function get_remain_on_date($objecttype, $object_id, $spentdate) {
		$remain = $this->findAll("entity_type = '{$objecttype}' and entity_id = '{$object_id}' and spentdate = '$spentdate'",array('timeremain'),'created desc',1);
		$total = 0;
		foreach ($remain as $obj) {
			foreach ($obj as $timeremain) {
				foreach ($timeremain as $spent) {
					$total = $spent==null?0:$spent;
				}
			}
		}
		return $total;
   }
 	/**
    * 获取在某日期之前业务对象的剩余工时
    *
    * @param string $objecttype 对象类型，如story、task等
    * @param int $object_id 对象id
    * @param string $spentdate 条件日期
    * @author joeyue
    * @access public
    * @return int 当日剩余工时
    */
   function get_remain_before_date($objecttype, $object_id, $spentdate) {
		$conditions = array('entity_type' => $objecttype, 'entity_id' => $object_id, 'spentdate' => "< $spentdate");
		$remain = $this->findAll($conditions, array('timeremain'), 'created desc', 1);
		if (empty($remain)) {
			if( 'story' == $objecttype) {
				$ret = g('Story', 'Model')->findById($object_id, array('effort') );
				return $ret['Story']['effort'];
			} else if('task' == $objecttype) {
				$ret = g('prong.Task', 'Model')->findById($object_id, array('effort') );
				return $ret['Task']['effort'];
			}
			return 0;
		} else {
			$total = 0;
			foreach ($remain as $obj) {
				foreach ($obj as $timeremain) {
					foreach ($timeremain as $spent) {
						$total = $spent==null?0:$spent;
					}
				}
			}
			return $total;
		}
   }
   /**
	* 返回的数据带entry的name
	*
	* 参数要用字符串形式
	*
	* @param mixed $conditions 条件
	* @param array $fields 查询字段
	* @param string $order 顺序
	* @param int $limit 数据量
	* @author kuncai
	* @access public
	* @return array 工时花费记录
	*/
   function get_timesheets_with_name($conditions = null, $fields = null, $order = null, $limit = null) {
		$timesheets = $this->findAll($conditions, $fields, $order, $limit);
		$story_obj = new Story();
		$task_obj = new Task();
		foreach ($timesheets as &$value) {
			if ($value['Timesheet']['entity_type'] == 'story') {
				$value['Timesheet']['name'] = $story_obj->field("name", array('id'=>$value['Timesheet']['entity_id']));
			} else {
				$value['Timesheet']['name'] = $task_obj->field("name", array('id'=>$value['Timesheet']['entity_id']));
	   		}
	   	}
	   	return $timesheets;
   }

   /**
	* 在项目之间移动workitem时,也要移动timesheet 2009-09-04
	*
	* @param int $entity_id 对象id
	* @param string $new_workspace_id 移入的workspace id
	* @author emilyzhang
	* @access public
	* @return boolean 是否成功
	*/
	function move_timesheet_workspace($entity_id, $new_workspace_id) {
		$timesheets = $this->findAll(array('entity_id' => $entity_id));
		if (!empty($timesheets)) {
			foreach ($timesheets as $timesheet) {
				$timesheet = $timesheet['Timesheet'];
				$entity_type = $timesheet['entity_type'];
				$entity_id = $timesheet['entity_id'];
				$timesheets_data = array(
												'id' => $timesheet['id'],
												'workspace_id' => $new_workspace_id
												);
				$this->save($timesheets_data);
			}
		}
		return true;
	}

	function save_move_timesheet_workspace($entity_type, $entity_ids, $new_workspace_id) {
		$update_fields = array('workspace_id' => $new_workspace_id);
		$this->updateAll($update_fields, array('entity_type' => $entity_type, 'entity_id' => $entity_ids));
		return true;
	}

	/**
	 * 移动花费记录
	 *
	 * @param int $target_entity 移动花费的目标业务对象
	 * @param int $source_entity 移动花费的原业务对象
	 * @param array $modified_fields 其他需要更改的timesheet字段(除entity_id或entity_type),特别注意，字符串参数值要手动叫上引号
	 * @return boolen 移动是否成功
	 * @author markguo
	 */
	function move_timesheets($target_entity, $source_entity, $modified_fields=array()) {
		/*
		 * $target_entity = array('entity_id' => 123, 'entity_type' => 'story');
		 * $source_entity = array('entity_id' => 456, 'entity_type' => 'task');
		 */

		$source_timesheet_conditions = $source_entity;
		$update_fields = array('entity_id' => $target_entity['entity_id'], 'entity_type' => $target_entity['entity_type'] );
		if (!empty($modified_fields)) { //特别注意，字符串参数值要手动叫上引号, array('owner' => "'abc'")
			foreach ($modified_fields as $field => $value) {
				$update_fields[$field] = $value;
			}
		}
		
		//更新该方法兼容跨bg移动
		$timesheets = $this->findAll($source_timesheet_conditions);
		if (!empty($timesheets)) {
			foreach ($timesheets as $timesheet) {
				$timesheets_data = $timesheet['Timesheet'];
				$this->del($timesheets_data['id']);
				unset($timesheets_data['id']);
				$timesheets_data = array_merge($timesheets_data, $update_fields);

				$this->save($timesheets_data);
			}
			return true;
		}


		return false;
	}

	/**
	 * 判断是否能够级联更新workitem_efforts表
	 *
	 * @param array $data 供判断的数据
	 * @access private
	 * @author robertyang
	 * @return boolean 是否需要级联更新
	 */
	function _need_update_effort($data) {
		if (!$this->no_need_update_effort && !empty($data['entity_type'])
				&& !empty($data['entity_id']) && !empty($data['workspace_id'])) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * 重写save方法，完成单人单日只能插入一条花费的功能
	 *
	 * @param array $data timesheet数据
	 * @param boolean $validate 是否需要数据校验
	 * @param array $fieldList 更新的字段列表
	 * @author robertyang
	 * @access public
	 * @param boolean 保存是否成功
	 */
	function save(&$data = null, $validate = true, $fieldList = array()) {
		if (!empty($data[$this->name])) {
			$data = $data[$this->name];
		}
		if (empty($data['owner'])) {
			$workspace_id = isset($data['workspace_id']) ? $data['workspace_id'] : null;
			$data['owner'] = current_user('nick', $workspace_id);
		}
		$this->append_id_if_exist($data);
		return parent::save($data, $validate, $fieldList);
	}

	function save_without_update_effort(&$data = null, $validate = true, $fieldList = array()) {
		$old_status = $this->no_need_update_effort;
		$this->no_need_update_effort = true;
		$ret = $this->save($data, $validate, $fieldList);
		$this->no_need_update_effort = $old_status;
		return $ret;
	}

	function append_id_if_exist(&$data) {
		if ($this->is_need_find_exist_timesheet($data)) {
			//如果是新增数据，则需要判断是否有已有的记录，有则为更新
			$timestamp = microtime(true);
			$timesheets_exist = $this->find(array(
													'entity_type' => $data['entity_type'],
													'entity_id' => $data['entity_id'],
													'spentdate' => $data['spentdate'],
													'owner' => $data['owner'],
													"$timestamp" => $timestamp
												));
			if (empty($timesheets_exist)) {
				return false;
			}
			return $data['id'] = $timesheets_exist[$this->name]['id'];
		}
		return false;
	}

	function is_need_find_exist_timesheet($data) {
		if (!empty($data['id'])) {
			return false;
		}
		if (empty($data['entity_type'])) {
			return false;
		}
		if (empty($data['spentdate'])) {
			return false;
		}
		if (empty($data['entity_id'])) {
			return false;
		}
		if (empty($data['owner'])) {
			return false;
		}
		return true;
	}

	/**
	 * 恢复需求的工时
	 * @param  [type] $story_id     [description]
	 * @param  [type] $workspace_id [description]
	 * @return [type]               [description]
	 */
	function get_story_effort( $story_id, $workspace_id ) {
		$story_timesheet = $this->findAll( array('workspace_id' => $workspace_id, 'entity_id' => $story_id, 'entity_type' => 'story'),
			'timespent,timeremain,exceed', 'created desc' );
		// debug($workspace_id);
		// debug($story_id);
		// debug($story_timesheet);die;
		//剩余工时以最后一条为准
		$story_effort = array('effort' => 0, 'remain' => 0, 'exceed' => 0, 'effort_completed' => 0, 'progress' => 0 );
		if( !empty($story_timesheet) ) {
			$story_effort['remain'] = $story_timesheet[0]['Timesheet']['timeremain'];
			foreach ($story_timesheet as $key => $value) {
				$story_effort['exceed'] += $value['Timesheet']['exceed'];
				$story_effort['effort_completed'] += $value['Timesheet']['timespent'];
			}
			$story_effort['effort'] = $story_effort['effort_completed'] + $story_effort['remain'] - $story_effort['exceed'];
			if( $story_effort['effort']+$story_effort['exceed'] == 0 ) {
				$story_effort['progress'] = 0;
			} else {
				$story_effort['progress'] = round(($story_effort['effort_completed']/($story_effort['effort']+$story_effort['exceed']) )*100);
			}
		}
		return $story_effort;
	}

	/**
	 * 获取人员的花费记录
	 *
	 * @param mixed $owners 人员
	 * @param string $date_begin 开始时间
	 * @param string $date_end 结束时间
	 * @param array $conditions 其他条件
	 * @access public
	 * @author robertyang
	 * @return array 花费记录和时间数组
	 */
	function get_timesheets_by_owner($owners, $date_begin, $date_end, $conditions = array()) {
	 	$ret = array();
	 	$date_array = $this->_get_time_range($date_begin, $date_end);
	 	$date_exists = array();

	 	$is_enabled_story = false;
	 	$is_enabled_task = false;

	 	$conditions = $this->deal_conditions($owners,$date_begin,$date_end,$conditions,$is_enabled_task,$is_enabled_story);
	 	$timesheets = $this->findAll($conditions, 'id,owner,spentdate,timespent,entity_type,entity_id,memo,workspace_id', 'spentdate asc');
	 	$timesheet_count = count($timesheets);
	 	$workitem_array = array();
 		$workspace = g('Workspace', 'Model');

	 	foreach ($timesheets as $timesheet) {
	 		$timesheet = $timesheet['Timesheet'];
	 		$workspace_name = $workspace->get_workspace_name_with_cache($timesheet['workspace_id']);
 	 		$ret[$timesheet['owner']][$timesheet['spentdate']][] = array(
				'id' => $timesheet['id'],
				'timespent' => $timesheet['timespent'],
				'entity_type' => $timesheet['entity_type'],
				'entity_id' => $timesheet['entity_id'],
				'memo' => $timesheet['memo'],
				'workspace_id' => $timesheet['workspace_id'],
				'workspace_name' => $workspace_name
			);
	 		$workitem_array[$timesheet['entity_type']][] = $timesheet['entity_id'];
	 		$date_exists[] = $timesheet['spentdate'];
	 	}

	 	foreach ($workitem_array as $type => $workitem_ids) {
	 		switch($type) {
	 			case 'story':
	 				loadModel('story');
					$story_db = new Story();
					$stories = $story_db->findAll(array('id' => $workitem_ids), 'id');
					unset($workitem_array[$type]);
					foreach ($stories as $story) {
						$workitem_array[$type][] = $story['Story']['id'];
					}
	 				break;
	 			case 'task':
	 				loadPluginModel('prong', 'task');
					$task_db = new Task();
					$tasks = $task_db->findAll(array('id' => $workitem_ids), 'id');
					unset($workitem_array[$type]);
					foreach ($tasks as $task) {
						$workitem_array[$type][] = $task['Task']['id'];
					}
	 				break;
	 			case 'bug':
	 				break;
	 		}
	 	}

	 	foreach ($ret as $owner => $timesheets) {
	 		foreach ($timesheets as $date => $timesheet) {
		 		foreach ($timesheet as $k => $v) {
		 			if(!empty($workitem_array[$v['entity_type']])&&(in_array($v['entity_id'], $workitem_array[$v['entity_type']]))) {

		 			} else {
		 				unset($ret[$owner][$date][$k]);
		 			}
		 			if(empty($ret[$owner][$date])){
		 				unset($ret[$owner][$date]);
		 			}
		 		}
		 		if(empty($ret[$owner])){
		 			unset($ret[$owner]);
		 		}
	 		}
	 	}

	 	foreach ($date_array as $key => $value) {
			if ((6 == $value['week_day'] || 0 == $value['week_day']) && !in_array($key, $date_exists)) {
				//如果是周末，且没有花费，则需要去掉该天
				unset($date_array[$key]);
			}

	 	}

	 	//统计横向总数和纵向总数
	 	$sum_by_owner = array();
	 	$sum_by_date = array();
	 	if (!empty($owners)) {
	 		foreach ($owners as $owner) {
	 			$owner_timesheet = !empty($ret[$owner])?($ret[$owner]):(array());
	 			$sum = 0;
	 			if (!empty($owner_timesheet)) {
	 				foreach ($owner_timesheet as $k=>$v) {
	 					if (!empty($v)) {
	 						foreach ($v as $kk=>$vv) {
	 							$sum += $vv['timespent'];
	 						}
	 					}
	 				}
	 			}
	 			$sum_by_owner[$owner] = $sum;
	 		}
	 	}

	 	if (!empty($date_array)) {
	 		foreach ($date_array as $date=>$week) {
	 			$sum = 0;
	 			if (!empty($ret)) {
	 				foreach ($ret as $k=>$v) {
	 					if (!empty($v[$date])) {
	 						foreach ($v[$date] as $kk=>$vv) {
	 							$sum += $vv['timespent'];
	 						}
	 					}
	 				}
	 			}
	 			$sum_by_date[$date] = $sum;
	 		}
	 	}
	 	$total_sum = array_sum($sum_by_owner);

	 	return array(
 			'ret' => $ret,
 			'date_range' => $date_array,
 			'timesheet_count' => $timesheet_count,
 			'total_by_owner' => $sum_by_owner,
 			'total_by_date' => $sum_by_date,
 			'total_sum' => $total_sum,
 			'is_enabled_story' => $is_enabled_story,
 			'is_enabled_task' =>  $is_enabled_task,
 		);
	}

	function resort_owners_by_timesheet_sepnt(&$owners, $date_begin, $date_end, $conditions = array()) {
	 	$conditions = $this->deal_conditions($owners,$date_begin,$date_end,$conditions,$is_enabled_task,$is_enabled_story);
	 	$data = $this->findAll($conditions, 'owner', 'spentdate asc');

	 	$participators = array();
	 	foreach($data as $item) {
	 		$item = $item['Timesheet'];
	 		$participators[] = $item['owner'];
	 	}

	 	$owners = array_merge($participators, $owners);
	 	$owners = array_values(array_unique($owners));
	}

	 /**
	 * 对外暴露获取condition接口 timesheet_export_service.php中会有调用。
	 * @author voladozhang
	 */
	function deal_conditions(&$owners,$date_begin,$date_end,$conditions,&$is_enabled_task,&$is_enabled_story){
	 	if (!is_array($owners)) {
	 		$owners = explode(';', $owners);
	 	}
	 	if (empty($owners)) {
	 		return array();
	 	}
	 	$conditions = $this->_deal_conditions_by_workspace_switches($conditions, $is_enabled_story, $is_enabled_task);
	 	$conditions = array_merge_recursive($conditions, array(
									'and' => array(
											array('spentdate' => '>=' . $date_begin),
											array('spentdate' => '<=' . $date_end),
										),
									'owner' => $owners,
								)
						);
	 	return $conditions;
	 }

	 /**
	  * 获取时间的范围数组
	  *
	  * @param string $date_begin 开始时间
	  * @param string $date_end 结束时间
	  */
	 function _get_time_range($date_begin, $date_end) {
	 	$ret = array();
	 	$duration = (strtotime($date_end) - strtotime($date_begin)) / (24 * 3600) + 1;
	 	$date_begin = strtotime($date_begin);
	 	for($i = 0; $i < $duration; $i ++) {
	 		$ret[date('Y-m-d', strtotime('+' . $i . ' day', $date_begin))]['week_day'] = date('w', strtotime('+' . $i . ' day', $date_begin));
	 	}
	 	return $ret;
	 }

	 /**
	  * 获取timesheet对应的workitem，包括有父需求的任务所对应的需求
	  *
	  * @param array &$timesheets 花费记录数组
	  * @access public
	  * @author robertyang
	  * @return array 对应的workitem
	  */
	function get_workitems_by_timesheets(&$timesheets) {
		$ret = array();
		$workitem_array = array();	//保存所有涉及到的工作
		$workspace_ids = array();

		foreach ($timesheets as $day_timesheets) {
			//遍历总的timesset
			foreach ($day_timesheets as $day_timesheet) {
				//由于当日有可能有多条timesheet，所以需要再次遍历
				$workitem_array[$day_timesheet['entity_type']][] = $day_timesheet['entity_id'];
				$workspace_ids[] = $day_timesheet['workspace_id'];
			}
		}

		$workspace_ids = array_unique($workspace_ids);
		$workspace_story_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($workspace_ids, 'story');
		$workspace_task_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($workspace_ids, 'task');
		$workspace_measurement_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($workspace_ids, 'measurement');

		if (!empty($workitem_array['task'])) {
			//获取所有有父需求的task对应的story id
			loadPluginModel('prong', 'task');
			$task_db = new Task();
			$not_leaf_tasks = $task_db->findAll(
									array(
											'id' => $workitem_array['task'],
											'not' => array(
												'or' => array('story_id' => 0),
												'or' => array('story_id' => null),
												'or' => array('story_id' => "")
											)

										),
									'id,story_id'
								);
			$not_leaf_task_ids = array();
			foreach ($not_leaf_tasks as $not_leaf_task) {
				$workitem_array['story'][] = $not_leaf_task['Task']['story_id'];
				$not_leaf_task_ids[] = $not_leaf_task['Task']['id'];
			}
		}

		ksort($workitem_array);	//按照key值排序，确保story先于task获取

		foreach ($workitem_array as $type => $workitem_ids) {
			//根据取得的id获得对应的实体对象数据
			switch ($type) {
				case 'story':
					$stories = g('Story')->findAll(array('id' => $workitem_ids), 'id,name,workspace_id,children_id');
					unset($workitem_array[$type]);
					foreach ($stories as $story) {
						$story = $story['Story'];
						if ((isset($workspace_story_switches[$story['workspace_id']]) && !$workspace_story_switches[$story['workspace_id']])
							|| (isset($workspace_measurement_switches[$story['workspace_id']]) && !$workspace_measurement_switches[$story['workspace_id']])) {
							//如果该workspace没有开需求模块和度量，则不需要展现
							continue;
						}
						$workitem_array[$type][$story['id']] = $story;
					}
					break;
				case 'task':
					$tasks = g('prong.Task')->findAll(array('id' => $workitem_ids), 'id,name,workspace_id,story_id');
					unset($workitem_array[$type]);
					foreach ($tasks as $task) {
						$task = $task['Task'];
						$workitem_array[$type][$task['id']] = $task;
						if ((isset($workspace_task_switches[$task['workspace_id']]) && !$workspace_task_switches[$task['workspace_id']])
							|| (isset($workspace_measurement_switches[$task['workspace_id']]) && !$workspace_measurement_switches[$task['workspace_id']])) {
							//如果该workspace没有开任务模块和度量，则不需要展现
							continue;
						}
						if (tapd_in_array($task['id'], $not_leaf_task_ids) && !empty($workitem_array['story'][$task['story_id']])
							&& (isset($workspace_story_switches[$task['workspace_id']]) && $workspace_story_switches[$task['workspace_id']])) {
							//任务有父需求，且开启了需求模块
							$workitem_array[$type][$task['id']]['story'] = $workitem_array['story'][$task['story_id']];
						}
					}
					break;
				case 'bug':
					//to do
					break;
			}
		}
		//将获得的业务对象放入timesheet中
		foreach ($timesheets as $date => $day_timesheets) {
			//遍历总的timesset
			foreach ($day_timesheets as $key => $day_timesheet) {
				//由于当日有可能有多条timesheet，所以需要再次遍历
				if (!empty($workitem_array[$day_timesheet['entity_type']][$day_timesheet['entity_id']])) {
					$timesheets[$date][$key]['workitem'] = $workitem_array[$day_timesheet['entity_type']][$day_timesheet['entity_id']];
				}
			}
		}
	}

	function get_objects_spent_before($entity_type, $objects, $date) {
		$entity_type = ucfirst (strtolower($entity_type));
		$spent = array();
		foreach ($objects as $obj) {
			$obj = isset($obj[$entity_type]) ? $obj[$entity_type] : $obj;
			$id = isset($obj['id']) ? $obj['id'] : $obj;
			$spent[$id] = $this->get_object_spent_before(strtolower($entity_type), $id, $date);
		}

		return $spent;
	}

	function get_object_spent_before($entity_type, $id, $date) {
		$function = "get_{$entity_type}_spent_before";
		return 	$this->$function($id, $date);
	}

	function get_task_spent_before($id, $date) {
		$spent = 0;
		$spent = $this->get_total_spent_before_date("task", $id, $date);
		return $spent;
	}

	function get_story_spent_before($id, $date) {
		$spent = 0;
		$task = new Task();
		$tasks = $task->get_tasks_belong_story(null, array($id), array('id'));
		if (empty($tasks)) {
			$spent = $this->get_total_spent_before_date("story", $id, $date);
		} else {
			foreach ($tasks as $task) {
				$task_id = $task['Task']['id'];
				$spent += $this->get_total_spent_before_date("task", $task_id, $date);
			}
		}
		return $spent;
	}

	/**
	 * 获取某个story或者task在某天的剩余工时
	 * 如果需求有子任务，则他的剩余工时是子任务的叠加
	 *
	 * @param string $entity_type  story or task
	 * @param int $id  story id or task id
	 * @param int $effort  story/task的预估工时
	 * @param date $date 时间
	 * @return int
	 * @access public
	 * @author kuncai
	 */
	function get_object_remain_in($entity_type, $id, $effort, $date) {
		$condition = array();
		$condition['entity_type'] = $entity_type;
		$condition['entity_id'] = $id;
		$condition['spentdate'] = "<= $date";
		$remain = 0;

		$timesheet = $this->findAll($condition, array("timeremain"), "spentdate DESC,modified DESC", 1);
		if (!empty($timesheet)) {
			$remain = $timesheet[0]['Timesheet']['timeremain'];
		} else {
			$remain = $effort;
		}
		if ("story" == $entity_type) {
			$task = new Task();
			$tasks = $task->get_tasks_belong_story(null, array($id), array('id', 'effort'));
			if (!empty($tasks)) {
				$remain = 0;
				foreach ((array)$tasks as $task) {
					$remain += $this->get_object_remain_in("task", $task['Task']['id'], $task['Task']['effort'], $date);
				}
			}
		}
		return $remain;
	}

	function get_object_passtotalspent_in($entity_type, $id, $effort, $date) {
		//当天过去所有花费
		$condition = array();
		$condition['entity_type'] = $entity_type;
		$condition['entity_id'] = $id;
		$condition['spentdate'] = "<= $date";
		$totalspent = 0;

		$timesheets = $this->findAll($condition, array("timespent"));
		foreach ((array)$timesheets as $timesheet) {
			$totalspent += $timesheet['Timesheet']['timespent'];
		}
		if ("story" == $entity_type) {
			$task = new Task();
			$tasks = $task->get_tasks_belong_story(null, array($id), array('id', 'effort'));
			if (!empty($tasks)) {
				$totalspent = 0;
				foreach ((array)$tasks as $task) {
					$totalspent += $this->get_object_passtotalspent_in("task", $task['Task']['id'], $task['Task']['effort'], $date);
				}
			}
		}
		return $totalspent;
	}

	/**
	 * 清除某个业务对象的花费记录
	 *
	 * @author joeyue
	 * @param string $entity_type 业务对象类型
	 * @param string $entity_id 业务对象id
	 * @return boolean
	*/
	function remove_all_timesheet($entity_type, $entity_id){
		if (empty($entity_type) || empty($entity_id) || !in_array($entity_type, array('story','task'))) {
			return false;
		}
		$time = microtime_float();
		$conditions = array('entity_type' => $entity_type,
							'entity_id' => $entity_id,
							"$time" => "$time"
						);
		$time_sheet_ids = $this->generateList($conditions, null, null, '{n}.Timesheet.id', '{n}.Timesheet.id');
		if (empty($time_sheet_ids)) {
			return true;
		}
		$flag = true;
		foreach ($time_sheet_ids as $time_sheet_id) {
			$flag &= $this->delete($time_sheet_id);
		}
		return $flag;
	}

	function get_timespent_by_iteration($type, $entity_ids, $workspace_id, $iteration_infor) {
		$timesheet_condition = array();
		$timesheet_condition['entity_id'] = $entity_ids;
		$timesheet_condition['workspace_id'] = $workspace_id;
		$timesheet_condition['entity_type'] = $type;
		if (!empty($iteration_infor['Iteration'])) {
			$timesheet_condition['spentdate'] = '>=' . $iteration_infor['Iteration']['startdate'];
			$timesheet_condition['and'] = array('spentdate' => '<=' . $iteration_infor['Iteration']['enddate']);
		}

		$time_field = array('entity_type','entity_id','timeremain','spentdate','timespent','memo','owner');
		return $this->findAll($timesheet_condition, $time_field, "spentdate asc");
	}

	/**
	 * 获取迭代内每天的花费，剩余工时。
	 * 一天内有多条剩余工时时，取最后更新的数据
	 *
	 * @param array $entity_ids story/task id集合
	 * @param int $workspace_id 项目id
	 * @param array $iteration_infor iteration对象
	 * @return array
	 * @author kuncai
	 */
	function get_timespent_timeremains($entity_ids, $workspace_id, $iteration_infor) {
		$timesheet_condition = array();
		$timesheet_condition['entity_id'] = $entity_ids;
		$timesheet_condition['workspace_id'] = $workspace_id;
		if (!empty($iteration_infor['Iteration'])) {
			$timesheet_condition['spentdate'] = '>=' . $iteration_infor['Iteration']['startdate'];
			$timesheet_condition['and'] = array('spentdate' => '<=' . $iteration_infor['Iteration']['enddate']);
		}

		$time_field = array('entity_type','entity_id','timeremain','spentdate','timespent','memo','owner');
		$time_spents = array();
		$time_spents = $this->findAll($timesheet_condition, $time_field, "modified DESC");
		$timesheet = array();
		foreach ($time_spents as $spent) {
			$entity_id = $spent['Timesheet']['entity_id'];
			$date = $spent['Timesheet']['spentdate'];
			if (!isset($timesheet[$entity_id][$date]['timeremain'])) {
				$timesheet[$entity_id][$date]['timeremain'] = $spent['Timesheet']['timeremain'];
			}
			$timespent = array();
			$timespent['spent'] = $spent['Timesheet']['timespent'];
			$timespent['owner'] = $spent['Timesheet']['owner'];
			$timespent['memo'] = $spent['Timesheet']['memo'];
			$timesheet[$entity_id][$date]['timespent'][] = $timespent;
		}
		return $timesheet;
	}

	/**
	 * 计算进度跟踪中的数据，包括story/task的剩余工时，总工时，颜色，花费情况
	 *
	 * @param array $story_tasks story/task集合，story的子任务放在story.tasks下
	 * @param array $time_spents 迭代花费情况，取 get_timespent_timeremains 的结果
	 * @param array $iteration_date 迭代时间数组
	 * @return array
	 * @author kuncai
	 */
	function process_story_tasks_time_spents($story_tasks, $time_spents, $iteration_date) {
		$workitems = $story_tasks;
		foreach ($story_tasks as $key=>$item) {

			$entity_type = isset($item['Story']) ? 'story' : 'task';
			$model = ucfirst($entity_type);
			$tasks = !empty($item['tasks']) ? $item['tasks'] : array();
			$item = isset($item['Story']) ? $item['Story'] : $item['Task'];
			$entity_id = $item['id'];
			$is_first = true;
			$remain = $item['effort'];
			$pre_timeremain = $item['effort'];
			//当天过去所有花费
			$totalspent = 0;
			foreach ($iteration_date as $date=>$value) {
				if ($is_first) {
					$is_first = false;
					$remain = $this->get_object_remain_in($entity_type, $entity_id, $item['effort'], $date);
					$totalspent = $this->get_object_passtotalspent_in($entity_type, $entity_id, $item['effort'], $date);
				} else {
					//如果当天有花费，则使用当天的剩余工时，否则剩余工时还是等于昨天的
					if (isset($time_spents[$entity_id][$date]['timeremain'])) {
						$remain = $time_spents[$entity_id][$date]['timeremain'];
					}
					if (isset($time_spents[$entity_id][$date]['timespent'])) {
						foreach ($time_spents[$entity_id][$date]['timespent'] as $timespent) {
							$totalspent += $timespent['spent'];
						}
					}
				}

				if (isset($time_spents[$entity_id][$date]['timespent'])) {
					$workitems[$key][$model]['spents'][$date]['timespent'] = $time_spents[$entity_id][$date]['timespent'];
				}

				$workitems[$key][$model]['spents'][$date]['timeremain'] = $remain;
				$workitems[$key][$model]['spents'][$date]['totalspent'] = $totalspent;
				$workitems[$key][$model]['spents'][$date]['color'] = $this->get_color($totalspent + $remain, $item['effort'], $remain, $pre_timeremain);
				$pre_timeremain = $remain;
			}

			if (!empty($tasks)) {
				$workitems[$key]['tasks'] = $this->process_story_tasks_time_spents($tasks, $time_spents, $iteration_date );
				//如果有任务，则需求的剩余工时由任务的剩余工时叠加
				$pre_timeremain = $item['effort'];
				//先将所有
				foreach ($iteration_date as $date=>$value) {
					$workitems[$key][$model]['spents'][$date]['timeremain'] = 0;
					$workitems[$key][$model]['spents'][$date]['totalspent'] = 0;
				}
				foreach ($workitems[$key]['tasks'] as $task) {
					foreach ($iteration_date as $date=>$value) {
						$workitems[$key][$model]['spents'][$date]['timeremain'] += $task['Task']['spents'][$date]['timeremain'];
						$workitems[$key][$model]['spents'][$date]['totalspent'] += $task['Task']['spents'][$date]['totalspent'];
						$workitems[$key][$model]['spents'][$date]['color'] = $this->get_color($workitems[$key][$model]['spents'][$date]['totalspent']+$task['Task']['spents'][$date]['timeremain'],
						$item['effort'], $remain, $pre_timeremain);
						$pre_timeremain = $workitems[$key][$model]['spents'][$date]['timeremain'];
					}
				}
			}

		}
		return $workitems;
	}

	/**
	 * 计算进度跟踪中的颜色
	 *
	 * @param int $totaleffort 当前总工时 = 当前过去总花费 + 当前剩余工时
	 * @param int $effort 预估工时
	 * @param int $timeremain 当前剩余工时
	 * @param int $pre_timeremain 前一天剩余工时
	 * @return string
	 * @author kuncai
	 */
	function get_color($totaleffort, $effort, $timeremain, $pre_timeremain) {
		if ($totaleffort <= $effort) {
			return "green";
		}

		if ($pre_timeremain >= $timeremain) {
			return "yellow";
		} else {
			return "red";
		}
	}

	/**
	 * 统计迭代每天的剩余工时
	 * 通过需求/任务的剩余工时进行叠加
	 *
	 * @param array $story_tasks process_story_tasks_time_spents的返回值
	 * @return array
	 * @author kuncai
	 */
	function get_total_spents_remains($story_tasks) {
		$total_times = array();
		foreach ($story_tasks as $item) {
			$entity_type = isset($item['Story']) ? 'story' : 'task';
			$model = ucfirst($entity_type);
			$tasks = !empty($item['tasks']) ? $item['tasks'] : array();
			$item = isset($item['Story']) ? $item['Story'] : $item['Task'];
			$entity_id = $item['id'];

			if (!empty($item['spents'])) {
				foreach($item['spents'] as $date=>$spent) {
					if (empty($total_times[$date]['timeremain'])) {
						 $total_times[$date]['timeremain'] = 0;
					}
					if (empty($total_times[$date]['totalspent'])) {
						 $total_times[$date]['totalspent'] = 0;
					}
					$total_times[$date]['timeremain'] += $spent['timeremain'];
					$total_times[$date]['totalspent'] += $spent['totalspent'];
				}
			}
		}
		return $total_times;
	}

	function merge_story_task_for_progress($all_stories, $all_tasks, $workspace) {
		$story_tasks = array();
		if (!empty($all_stories)) {
			foreach ($all_stories as $key => $value) {
				$story_tasks[$key] = $value;
				if ($workspace['switches']['sw_task']) {
					if (!empty($all_tasks)) {
						foreach ($all_tasks as $k => $v) {
							if ($value['Story']['id'] == $v['Task']['story_id']) {
								$story_tasks[$key]['tasks'][] = $v;
								unset($all_tasks[$k]);
							}
						}
					}
				}
			}
			//需求和任务的数据
			if ($workspace['switches']['sw_task']) {
				$story_tasks = array_merge($story_tasks, $all_tasks);
			}
		} else {
			$story_tasks = $all_tasks;
		}
		return $story_tasks;
	}

	/**
	 * 判断是否补填工时
	 *
	 * @author joeyue
	 * @param string $date 补填时间
	 * @param string $objtype 对象类型
	 * @param string $obj_id 对象id
	 * @return bool 如果是补填则返回true，否则返回false
	 */
	function is_add_timesheet_for_last_date($date, $objtype, $obj_id) {
		$conditions = array('entity_type' => $objtype, 'entity_id' => $obj_id, 'spentdate' => "> $date");
		$count = $this->findCount($conditions);
		return !empty($count) ? true : false;
	}

	/**
	 * 修改最近一条timesheet的剩余工时
	 *
	 * @author joeyue
	 * @param string $objtype 对象类型
	 * @param string $obj_id 对象id
	 * @return bool
	 */
	function change_recent_timesheet_for_object($timeremain, $objtype, $obj_id) {
		$conditions = array('entity_type' => $objtype, 'entity_id' => $obj_id);
		$ret = $this->findAll($conditions, null, 'spentdate desc, id desc', 1);
		if (empty($ret)) {
			return true;
		}
		foreach ($ret as $timesheet) {
			$timesheet['Timesheet']['timeremain'] = $timeremain;
			return $this->save($timesheet['Timesheet']);
		}
	}

	/**
	 * 保存Timesheet
	 *
	 * @author joeyue
	 * @param int $is_edit 是否是编辑
	 * @param int $object_id 工时所属业务对象(需求/任务)的ID
	 * @param int $remain_effort 剩余工时
	 * @return boolean
	 */
	function save_timesheet($entity_type, $object_id, $remain_effort, $data, $workspace_id, $is_edit = false){
		$data['Timesheet']['workspace_id'] = $workspace_id;
		$data['Timesheet']['owner'] = current_user('nick', $workspace_id);
		return $this->save_timesheet_new($data);
	}

	/**
	 * 新的timesheet保存逻辑，替换原来的save_timesheet
	 *
	 *	@author kuncai
	 */
	function save_timesheet_new($data) {
		$data = isset($data['Timesheet']) ? $data['Timesheet'] : $data;
		if (!$this->validates($data) || !$this->validate_not_empty($data)) {
			return false;
		}
		$data['owner'] = !empty($data['owner']) ? $data['owner'] : current_user('nick', $data['workspace_id']);
		$this->append_id_if_exist($data);
		if (empty($data['id'])) {
			return $this->add_timesheet($data);
		} else {
			return $this->update_timesheet($data);
		}
	}

	function validate_not_empty($data) {
		if (empty($data['entity_id'])) {
			return false;
		}
		if (empty($data['entity_type'])) {
			return false;
		}
		if (empty($data['owner'])) {
			return false;
		}
		if (empty($data['spentdate'])) {
			return false;
		}
		if (empty($data['workspace_id'])) {
			return false;
		}
		if (!isset($data['timeremain'])) {
			return false;
		}
		if (!isset($data['timespent'])) {
			return false;
		}
		return true;
	}

	function update_timesheet($data) {
		$remain = $data['timeremain'];//对象总剩余工时
		$effort = $this->get_effort_by_entity($data['entity_type'], $data['entity_id']);
		$org_timesheet = $this->find(array('id'=>$data['id']));
		$total_spent = $data['timespent'] + $this->get_total_spent_by_spentdate_createdate($data['entity_type'], $data['entity_id'], $data['spentdate'],$org_timesheet['Timesheet']['created']);
		$data['timeremain'] = $this->cal_timeremain($total_spent, $effort);
		$data['exceed'] = $this->cal_exceed($total_spent, $data['timeremain'], $effort);
		$data['created'] = $org_timesheet['Timesheet']['created'];
		if (!$this->save($data)) {
			return false;
		}
		$this->update_timesheets_after_add_timesheet($data);
		$this->update_last_timesheet_after_add_timesheet($data, $remain);
		return true;
	}

	function is_add_timesheet_in_feature($spentdate) {
		if (strtotime($spentdate) > strtotime(date("Y-m-d"))) {
			return true;
		}
		return false;
	}

	function add_timesheet($data) {
		if ($this->is_add_timesheet_in_feature($data['spentdate'])) {
			return false;
		}
		$remain = $data['timeremain'];//对象总剩余工时
		$effort = $this->get_effort_by_entity($data['entity_type'], $data['entity_id']);
		$total_spent = $data['timespent'] + $this->get_total_spent_on_date($data['entity_type'], $data['entity_id'], $data['spentdate']);
		$data['timeremain'] = $this->cal_timeremain($total_spent, $effort);
		$data['exceed'] = $this->cal_exceed($total_spent, $data['timeremain'], $effort);
		$data['created'] = date('Y-m-d H:i:s');
		if (!$this->save($data)) {
			return false;
		}
		$condition['id'] = !empty($data['id']) ? $data['id'] :$this->getLastInsertId();
		$rand = microtime(true);
		$condition["$rand"] = "$rand";
		$data = $this->find($condition);
		$this->update_timesheets_after_add_timesheet($data['Timesheet']);
		$this->update_last_timesheet_after_add_timesheet($data['Timesheet'], $remain);
		return true;
	}

	function get_effort_by_entity($entity_type, $entity_id) {
		$entity_model_name = ucfirst($entity_type);
		$entity_model = g($entity_model_name);
		$entity = $entity_model->find(array('id'=>$entity_id), array('effort'));
		$effort = $entity[$entity_model_name]['effort'];
		return $effort;
	}

	function is_have_timespent_by_entity($entity_type, $entity_id){
		$count = $this->findCount(array(
			'entity_type' => $entity_type,
			'entity_id' => $entity_id
		));
		if ($count > 0){
			return true;
		} else {
			return false;
		}
	}

	function update_last_timesheet_after_add_timesheet($add_timesheet, $remain) {
		//对象总剩余工时应该保存在最后一条timesheet覆盖掉上面自动计算的值
		$last_timesheet = $this->get_recentTimesheet($add_timesheet['entity_type'], $add_timesheet['entity_id']);
		if (empty($last_timesheet)) {
			return true;
		}
		$last_timesheet = $last_timesheet['Timesheet'];
		$last_timesheet['timeremain'] = $remain;//对象总剩余工时
		$total_spent = $this->get_totalSpents($last_timesheet['entity_type'], $last_timesheet['entity_id']);
		$effort = $this->get_effort_by_entity($add_timesheet['entity_type'], $add_timesheet['entity_id']);
		$last_timesheet['exceed'] = $this->cal_exceed($total_spent, $last_timesheet['timeremain'], $effort);
		if (!$this->save($last_timesheet)) {
			return false;
		}
		return true;
	}

	function update_timesheets_after_add_timesheet($add_timesheet) {
		$timesheets = $this->find_timesheets_after($add_timesheet);
		if (empty($timesheets)) {
			return;
		}
		$effort = $this->get_effort_by_entity($add_timesheet['entity_type'], $add_timesheet['entity_id']);
		foreach ($timesheets as $timesheet) {
			$timesheet = $timesheet['Timesheet'];
			$total_spent =  $timesheet['timespent'] + $this->get_total_spent_by_spentdate_createdate($timesheet['entity_type'], $timesheet['entity_id'], $timesheet['spentdate'], $timesheet['created']);
			$timesheet['timeremain'] = $this->cal_timeremain($total_spent, $effort);
			$timesheet['exceed'] = $this->cal_exceed($total_spent, $timesheet['timeremain'], $effort);
			$this->create();
			$this->save($timesheet);
		}
	}

	function cal_timeremain($total_spent, $effort) {
		$timeremain= $effort - $total_spent;
		$timeremain = ($timeremain < 0) ? 0 : $timeremain;
		return $timeremain;
	}

	/**
	 * 当前花费后面的所有花费,无序
	 *
	 * @param unknown_type $timesheet
	 * @return unknown
	 */

	function find_timesheets_after($timesheet) {
		$condition = array();
		$condition['entity_id'] = $timesheet['entity_id'];
		$condition['entity_type'] = $timesheet['entity_type'];
		$condition['and']['or'][] = array('spentdate' => "> {$timesheet['spentdate']}");
		$condition['and']['or'][] = array(
										'and' => array(
											'spentdate' => $timesheet['spentdate'],
											'created' => "> {$timesheet['created']}"
										)
								   );
		$rand = microtime(true);
		$condition['workspace_id'] = $timesheet['workspace_id'];
		$condition["$rand"] = "$rand";
		return $this->findAll($condition, null, "spentdate ASC, created ASC");
	}

	/**
	 * 计算超出工时，如果是超出则返回正数，如果是提前则返回负数
	 *
	 * @param unknown_type $total_spent 总实际花费
	 * @param unknown_type $timeremain 剩余工时
	 * @param unknown_type $effort   预估工时
	 * @return 如果是超出则返回正数，如果是提前则返回负数
	 */
	function cal_exceed($total_spent, $timeremain, $effort) {
		return number_format($total_spent + $timeremain - $effort, 2);
	}

	function _deal_conditions_by_workspace_switches($conditions, &$is_enabled_story, &$is_enabled_task) {
		if (isset($conditions['workspace_id']) && is_array($conditions['workspace_id'])) {
			$workspace_story_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($conditions['workspace_id'], 'story');
			$workspace_task_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($conditions['workspace_id'], 'task');
			$workspace_measurement_switches = g('SwitchFunc')->is_enabled_object_by_workspace_ids($conditions['workspace_id'], 'measurement');

			$story_workspace_ids = array();
			$task_workspace_ids = array();

			foreach($workspace_story_switches as $workspace_id => $enabled) {
				if ($enabled && isset($workspace_measurement_switches[$workspace_id]) && $workspace_measurement_switches[$workspace_id]) {
					//启用需求模块且启用了度量
					$is_enabled_story = true;
					$story_workspace_ids[] = $workspace_id;
				}
			}

			foreach($workspace_task_switches as $workspace_id => $enabled) {
				if ($enabled && isset($workspace_measurement_switches[$workspace_id]) && $workspace_measurement_switches[$workspace_id]) {
					//启用任务模块且启用了度量
					$is_enabled_task = true;
					$task_workspace_ids[] = $workspace_id;
				}
			}
			unset($conditions['workspace_id']);
			if (!empty($story_workspace_ids) && !empty($task_workspace_ids)) {
				$conditions['and'] = array(
								'or' => array(
										array(
											'workspace_id' => $story_workspace_ids,
											'entity_type' => 'story'
										),
										array(
											'workspace_id' => $task_workspace_ids,
											'entity_type' => 'task'
										)
									)
					);
			} else if (!empty($story_workspace_ids)) {
				$conditions['workspace_id'] = $story_workspace_ids;
				$conditions['entity_type'] = 'story';
			} else if (!empty($task_workspace_ids)) {
				$conditions['workspace_id'] = $task_workspace_ids;
				$conditions['entity_type'] = 'task';
			}
			return $conditions;
		} else {
			return $conditions;
		}
	}
	public function get_api_fields() {
		return array('id', 'entity_type', 'entity_id', 'timespent', 'spentdate', 'owner', 'created', 'workspace_id', 'memo');
	}

	public function api_conditions_fixer(&$conditions) {
		$workspace_id = $conditions['workspace_id'];
		if (array_key_exists('entity_id', $conditions)) {
			$id = $conditions['entity_id'];
			if (array_key_exists('entity_type', $conditions)) {
				if ($conditions['entity_type']=='story') {
					$exist = g('Story', 'Model')->findCount(array('workspace_id'=>$workspace_id, 'id'=>$id));
				} elseif ($conditions['entity_type']=='task') {
					$exist = g('prong.Task', 'Model')->findCount(array('workspace_id'=>$workspace_id, 'id'=>$id));
				}
				if (!$exist) {
					$conditions['id'] = 0;	// 如果对应 entity_id 不存在，则把构造个恒假的条件。以返回空数据
				}
			}
		} else {
			if (array_key_exists('entity_type', $conditions)) {
				if ($conditions['entity_type']=='story') {
					$exclude_ids = g('prong.RemovedStory', 'Model')->simple_generate_list(array('workspace_id'=>$workspace_id), null, null, 'id', 'id');
				} elseif ($conditions['entity_type']=='task') {
					$exclude_ids = g('prong.RemovedTask', 'Model')->simple_generate_list(array('workspace_id'=>$workspace_id), null, null, 'id', 'id');
				}
				$conditions['NOT'] = array('entity_id'=>array_values($exclude_ids)); // 把已被删除的实体ID排除掉
			} else {
				$story_exclude_ids = g('prong.RemovedStory', 'Model')->simple_generate_list(array('workspace_id'=>$workspace_id), null, null, 'id', 'id');
				$task_exclude_ids = g('prong.RemovedTask', 'Model')->simple_generate_list(array('workspace_id'=>$workspace_id), null, null, 'id', 'id');
				if ( !empty($story_exclude_ids) || !empty($task_exclude_ids) ) {	// 如果项目需求、任务已删除数据都不为空，把已被删除的实体ID排除掉
					$conditions['AND'] = array('OR'=>array(
						array('entity_type'=>'story', 'NOT'=>array('entity_id'=>array_values($story_exclude_ids))),
						array('entity_type'=>'task', 'NOT'=>array('entity_id'=>array_values($task_exclude_ids))),
					));
				}
			}
		}
		# debug($conditions);
	}

	public function get_api_doc_all_fields($workspace_id) {
		$all_fields = parent::get_api_doc_all_fields($workspace_id);
		$all_fields['entity_type']['label'] = '对象类型，如story、task等';
		$all_fields['entity_id']['label'] = '对象ID';
		$all_fields['timespent']['label'] = '花费工时';
		$all_fields['spentdate']['label'] = '花费日期';
		$all_fields['owner']['label'] = '花费创建人';
		$all_fields['memo']['label'] = '花费描述';

		return $all_fields;
	}

	function get_exisits_entity_ids($conditions) {
		$stories_ids = $task_ids = $real_stories_ids = $real_tasks_ids = array();
		$entites = $this->findAll($conditions, array('entity_type', 'entity_id'));
		foreach ($entites as $key => $entity) {
			$entity = $entity['Timesheet'];
			if ('story' == $entity['entity_type']) {
				$stories_ids[] = $entity['entity_id'];
			}

			if ('task' == $entity['entity_type']) {
				$task_ids[] = $entity['entity_id'];
			}
		}

		$real_stories_ids = [];
		if (!empty($stories_ids)) {
			$real_stories_ids = g('Story')->generateList(array('id' => $stories_ids), null, null, "{n}.Story.id", "{n}.Story.id");
			$real_stories_ids = !empty($real_stories_ids) ? array_values($real_stories_ids) : [];
		}

		$real_tasks_ids = [];
		if (!empty($task_ids)) {
			$real_tasks_ids = g('prong.Task')->generateList(array('id' => $task_ids), null, null, "{n}.Task.id", "{n}.Task.id");
			$real_tasks_ids = !empty($real_tasks_ids) ? array_values($real_tasks_ids) : [];
		}

		$real_entity_ids = array_unique(array_merge($real_stories_ids, $real_tasks_ids));
		return $real_entity_ids;
	}
}

