<?php
namespace MinEduAPI\Controller;


use Common\Lib\EduV1\EduSysCacheMgr;

use Common\Lib\MallUtils\MallFuncUtil;
use Common\Lib\FuncUtils\TmplMsgUtil;
use Common\Lib\FuncUtils\TmplValueBean;
use Common\Lib\RedisSet\Goods;
use Common\Lib\FuncUtils\ImageFuncUtil;

class MessageController extends BaseController {

	protected function _initialize() {
		parent::_initialize();
	}
	const Z_MSG_TYPE ='MESSAGE_SORTEDSET_';//有序集合前缀,依次顺序拼接消息类型、用户id、发出（F）或收到（T）类型、是（Y）否（N）阅读
	const H_MSG_TYPE = 'MESSAGE_';//哈希消息类型key,拼接班级id
	//进入家校互动留言界面
	public function editMessage(){
// 		$chat_num = getVar('chat_num');
		$comp_id = $this->cur_comp_id;
		$vip_id = $this->vip_id;
		if(empty($vip_id)){
			$this->errorReturn('初始化出错；请加入班级');
		}
		$msg_rec=M('class_chat')->where(array('comp_id'=>$comp_id,'vip_id'=>$vip_id,'msg_status'=>'N'))->find();
		if(empty($msg_rec)){
			$chat_num=$vip_id.'_'.MallFuncUtil::makeBillno();
			//插入一条草稿
			$raw_data = array(
					"comp_id"=>$comp_id,
					"chat_num"=>$chat_num,
					'vip_id'=>$vip_id,
					"content"=>'',
					"chat_date"=>getdatetime(time()),
					"msg_status"=>'N',
					"orig_chat_num"=>$chat_num
			);
	
			$ret = M('class_chat')->add($raw_data);
			if($ret===false){
				$this->errorReturn('初始化出错');
			}
			//返回id
			$this->ajaxReturn(array(
					'success'=>1,
					'chat_num'=>$chat_num
			));
		}else{
			$replyimg=EduSysCacheMgr::getMessageImg($comp_id, $msg_rec['chat_num']);
			$replyAttachment=$this->attachment($replyimg);
			$msg_rec['video']=$replyAttachment['video'];
			$msg_rec['recording']=$replyAttachment['recording'];
			$msg_rec['picture']=$replyAttachment['picture'];
			$this->ajaxReturn(array(
					'success'=>1,
					'data'=>$msg_rec,
					'chat_num'=>$msg_rec['chat_num']
			));
		}
	
	}
	//上传附件
	public function uploadComentPic(){
		$datatype='CHAT_MSG';
		$datanum=getVar("chat_num");
		$seconds=getVar('seconds');
		if(empty($datanum)){
			$this->errorReturn('缺少参数');
		}
		$datafield = MallFuncUtil::makeBillno();
	
		$comp_id = $this->cur_comp_id;
		$vip = $this->vip;
	
		$errmsg='';
	
		$ret_pic = ImageFuncUtil::uploadImage($comp_id, $datatype, $datanum, $datafield, $vip['vip_name'],$seconds, $errmsg);
	
		if($ret_pic === false){
			rollback();
			$this->errorReturn('上传失败;1');
		}
		$type=substr($ret_pic['picurl'],-4);
		if($type=='.mp4'){
			$media_type='VIDEO';
			//首帧图片
			$first_image_url = $ret_pic['picurl'].'.0_0.p0.jpg';
			//直接更新封面，且标记未压缩类视频
			$ret2 = M('pic_data')
			->where(array('comp_id'=>$comp_id,'data_type'=>$datatype,'data_num'=>$datanum,'data_field'=>$datafield))
			->save(array(
					'first_image_url'=>$first_image_url,
					'is_compress'=>'Y'
			));
		}elseif ($type == '.m4a' || $type == '.mp3' || $type == '.aac'){
			$media_type='RADIO';
		}else {
			$media_type='IMAGE';
		}
	
		$ret = M('class_chat_image')->add(array(
				'comp_id'=>$comp_id,
				'chat_num'=>$datanum,
				'image_num'=>$datafield,
				'media_type'=>$media_type,
				'createdate'=>getdatetime(time()),
				'createname'=>$vip['vip_name'],
				'modifydate'=>getdatetime(time()),
				'modifyname'=>$vip['vip_name']
		));
	
		if($ret===false){
			rollback();
			$this->errorReturn('上传失败;1000');
		}
		EduSysCacheMgr::reSetMessageImg($comp_id,$datanum);
		$image_url = '';
		if(!empty($ret_pic['picurl'])){
			$image_url =  C('COS_PIC_PUBLIC').$ret_pic['picurl'];
		}
		$this->ajaxReturn(array("success"=>"1","msg"=>'上传成功',"image_num"=>$datafield,"image_url"=>$image_url,'seconds'=>$seconds));
	}
	//保存留言
	public function messageSave(){
		$comp_id=$this->cur_comp_id;
		$vip_id=$this->vip_id;
		$vip=getVar('vip');
		$chat_num=getVar("chat_num");
		$orig_chat_num=getVar('orig_chat_num');
		if(empty($chat_num)){
			$this->errorReturn('系统错误');
		}
		$content=getVar('content');
		$errmsg='';
		$msg=$this->textSafety($content, $errmsg);
		if($msg != 0){
			$this->errorReturn('文字有不合法内容');
		}
		$raw_data = array(
			"comp_id"=>$comp_id,
			"chat_num"=>$chat_num,
			'vip_id'=>$vip_id,
			"content"=>$content,
			"chat_date"=>getdatetime(time()),
			"msg_status"=>'Y',
			"orig_chat_num"=>$orig_chat_num?$orig_chat_num:$chat_num
		);
		startTrans();
		$ret = M('class_chat')->where(array('comp_id'=>$comp_id,'chat_num'=>$chat_num))->save($raw_data);
		if(!$ret){
			p_file('消息保存失败'.getDbError());
			$this->errorReturn('保存失败');
		}
		$add=array(
			'comp_id'=>$comp_id,
			'chat_num'=>$chat_num,
			'chat_date'=>getdatetime(time()),
			'read_flag'=>'N',
		);
		$cache=new Goods();
			foreach ($vip as $k=>$v){
				$add['recv_vip_id']=$v;
				$results=M('class_chat_recv')->add($add);
				if(!$results){
					rollback();
					p_file('消息保存失败1'.getDbError());
					p_file('保存数据'.json_encode($add));
					$this->errorReturn('保存失败');
				}
				$cache->Add(self::Z_MSG_TYPE.'M_'.$v.'_T_N', strtotime($add['chat_date']), $chat_num);
				$cache->Add(self::Z_MSG_TYPE.'M_'.$v.'_T', strtotime($add['chat_date']), $chat_num);
				$to_vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id,$v);
				$from_vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id,$vip_id);
				if($to_vip_info['vip_lvl']=='02'){
					$title="尊敬的 ".$to_vip_info['vip_name']."老师，您收到了一条留言";
					$name=$from_vip_info['othername1'];
					$remark="点击查看留言详情并回复";
				}else{
					$title="尊敬的家长，您收到了一条老师留言";
					$name=$to_vip_info['othername1'];
					$remark="感谢您的使用， 点击查看详情。感谢你对学校工作的支持。";
				}
				$this->sendTemplateMessage($v,$chat_num,$title,$name,$content,$remark);
			}
		commit();
		$cache->Add(self::Z_MSG_TYPE.'M_'.$vip_id.'_F', strtotime($add['chat_date']), $chat_num);
		if(!empty($orig_chat_num)){
			EduSysCacheMgr::updateMessageDetailAll($comp_id, $orig_chat_num, $raw_data);
		}
		$this->successReturn('保存成功');
	}
	//我发出的留言列表
	public function formMessageList(){
		$vip_id=$this->vip_id;
		$comp_id=$this->cur_comp_id;
		$page=getVar('page');
		if(empty($page)){
			$page=1;
		}
		$pagesize=10;
		$cache=new Goods();
		$messageNum=$cache->Card(self::Z_MSG_TYPE.'M_'.$vip_id.'_F');
		if(empty($messageNum)){
			$where=array(
					'comp_id'=>$comp_id,
					'vip_id'=>$vip_id
			);
			$message=M('class_chat')->field('chat_num,chat_date')->where($where)->order('chat_date desc')->select();
			foreach ($message as $k=>$v){
				$cache->Add(self::Z_MSG_TYPE.'M_'.$vip_id.'_F', strtotime($v['chat_date']), $v['chat_num']);
			}
		}
		if($page == 1){
			$messageId=$cache->RevRange(self::Z_MSG_TYPE.'M_'.$vip_id.'_F',0,$page*10-1);
		}else {
			$messageId=$cache->RevRange(self::Z_MSG_TYPE.'M_'.$vip_id.'_F',($page-1)*10,$page*10-1);
		}
		$list=array();
		foreach ($messageId as $k=>$v){
			$message=EduSysCacheMgr::getMessageDetailForm($comp_id,$v);
			if(empty($message)){
				continue;
			}
				$data=EduSysCacheMgr::getMessageDetailFormVip($comp_id,$v);
				$tovip['num']=count($data);
				$vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id, $data[0]['recv_vip_id']);
				if($vip_info['vip_lvl'] == '02'){
					$tovip['name']=$vip_info['vip_name'].$vip_info['course_name'].'老师';
				}else {
					$tovip['name']=$vip_info['othername1'].$vip_info['familytree_name'];
				}
				$message['to_vip']=$tovip;
			$img=EduSysCacheMgr::getMessageImg($comp_id,$v);
			$replyAttachment=$this->attachment($img);
			$message['video']=$replyAttachment['video'];
			$message['recording']=$replyAttachment['recording'];
			$message['picture']=$replyAttachment['picture'];
			array_push($list, $message);
		}
		$this->ajaxReturn(array(
			'success'=>1,
			'data'=>$list,
			'load_end'=>(count($messageId)<$pagesize) ? true : false,
		));
	}
	//我收到的留言列表
	public function toMessageList(){
		$vip_id=$this->vip_id;
		$comp_id=$this->cur_comp_id;
		$page=getVar('page');
		$read=getVar('read');
		if(!empty($read)){
			$suffix='_T'.'_'.$read;
		}else {
			$suffix='_T';
		}
		if(empty($page)){
			$page=1;
		}
		$pagesize=10;
		$cache=new Goods();
		$messageNum=$cache->Card(self::Z_MSG_TYPE.'M_'.$vip_id.$suffix);
		if(empty($messageNum)){
			$where=array(
					'comp_id'=>$comp_id,
					'recv_vip_id'=>$vip_id
			);
			if(!empty($read) && $read === 'N'){
				$where['read_flag'] = 'N';
			}
			if(!empty($read) && $read === 'Y'){
				$where['read_flag'] = 'Y';
			}
			$message=M('class_chat_recv')->field('chat_num,chat_date')->where($where)->order('chat_date desc')->select();
			foreach ($message as $k=>$v){
				$cache->Add(self::Z_MSG_TYPE.'M_'.$vip_id.$suffix, strtotime($v['chat_date']), $v['chat_num']);
			}
		}
		if($page == 1){
			$messageId=$cache->RevRange(self::Z_MSG_TYPE.'M_'.$vip_id.$suffix,0,$page*10-1);
		}else {
			$messageId=$cache->RevRange(self::Z_MSG_TYPE.'M_'.$vip_id.$suffix,($page-1)*10,$page*10-1);
		}
		$list=array();
		foreach ($messageId as $k=>$v){
			$message=EduSysCacheMgr::getMessageDetailForm($comp_id,$v);
			if(empty($message)){
				continue;
			}
			$orig_chat=EduSysCacheMgr::getMessageDetailForm($comp_id,$message['orig_chat_num']);
			$chat['orig_chat_num']=$message['orig_chat_num'];
			$chat['content']=$orig_chat['content'];
			$chat_user=EduSysCacheMgr::getUserVarDataByVid($comp_id, $orig_chat['vip_id']);
			$chat['headimgurl']=$chat_user['headimgurl'];
			$message['orig_chat']=$chat;
			$vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id, $message['vip_id']);
			if($vip_info['vip_lvl']  == '02'){
    			$message['to_vipname']=$vip_info['vip_name'].$vip_info['course_name'].'老师';
    		}else {
    			$message['to_vipname']=$vip_info['othername1'].$vip_info['familytree_name'];
    		}
    		$message['to_vipid']=$vip_info['vip_id'];
			$message['to_vipheadimg']=$vip_info['headimgurl'];
			$img=EduSysCacheMgr::getMessageImg($comp_id,$v);
			$replyAttachment=$this->attachment($img);
			$message['video']=$replyAttachment['video'];
			$message['recording']=$replyAttachment['recording'];
			$message['picture']=$replyAttachment['picture'];
			array_push($list, $message);
		}
		$is_num=$cache->Card(self::Z_MSG_TYPE.'M_'.$vip_id.'_T_N');
		$this->ajaxReturn(array(
				'success'=>1,
				'data'=>$list,
				'load_end'=>(count($messageId)<$pagesize) ? true : false,
				'is_read'=>empty($is_num)?false:true,
		));
	}
	//留言详情
	public function messageDetail(){
		$type=getVar('type'); //F从我的留言列表进入,T从我收到的留言列表进入,S从我的留言列表卡片下的原始留言箭头进入
		$vip_id=$this->vip_id;
		$comp_id=$this->cur_comp_id;
		$chat_num=getVar('chat_num');
		$vipid=getVar('vip_id');
		$message=EduSysCacheMgr::getMessageDetailForm($comp_id,$chat_num);
		$vip=EduSysCacheMgr::getUserVarDataByVid($comp_id, $message['vip_id']);
		if($vip['vip_lvl']  == '02'){
			$message['to_vipname']=$vip['vip_name'].$vip['course_name'].'老师';
		}else {
			$message['to_vipname']=$vip['othername1'].$vip['familytree_name'];
		}
		$message['vip_name']=$vip['othername1'].$vip['familytree_name'];
		$message['headimg']=$vip['headimgurl'];
		$img=EduSysCacheMgr::getMessageImg($comp_id,$chat_num);
		$replyAttachment=$this->attachment($img);
		$message['video']=$replyAttachment['video'];
		$message['recording']=$replyAttachment['recording'];
		$message['picture']=$replyAttachment['picture'];
		$cache=new Goods();
		M('class_chat_recv')->where(array('comp_id'=>$comp_id,'chat_num'=>$message['chat_num'],'recv_vip_id'=>$vip_id))->save(array('read_flag'=>'Y','read_date'=>getdatetime(time())));
		if($message['vip_id'] != $vip_id){
			$cache->Rem(self::Z_MSG_TYPE.'M_'.$vip_id.'_T_N', $message['chat_num']);
			$cache->Add(self::Z_MSG_TYPE.'M_'.$vip_id.'_T_Y', strtotime($message['chat_date']), $message['chat_num']);
		}
		if($type === 'S'){
			$chat=EduSysCacheMgr::getMessageDetailAll($comp_id,$chat_num);
			if(!empty($chat)){
				foreach ($chat as $k=>$v){
					if($v['vip_id'] != $vip_id && $v['vip_id'] != $vipid){
						unset($chat[$k]);
						continue;
					}
					$vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id, $v['vip_id']);
// 					$chat[$k]['vip_name']=$vip['othername1'].$vip['familytree_name'];
					if($vip_info['vip_lvl']  == '02'){
						$chat[$k]['vip_name']=$vip_info['vip_name'].$vip_info['course_name'].'老师';
					}else {
						$chat[$k]['vip_name']=$vip_info['othername1'].$vip_info['familytree_name'];
					}
					$chat[$k]['headimg']=$vip_info['headimgurl'];
					$chatimg=EduSysCacheMgr::getMessageImg($comp_id,$v['chat_num']);
					$replyAttachment=$this->attachment($chatimg);
					$chat[$k]['video']=$replyAttachment['video'];
					$chat[$k]['recording']=$replyAttachment['recording'];
					$chat[$k]['picture']=$replyAttachment['picture'];
					M('class_chat_recv')->where(array('comp_id'=>$comp_id,'chat_num'=>$v['chat_num'],'recv_vip_id'=>$vip_id))->save(array('read_flag'=>'Y','read_date'=>getdatetime(time())));
// 					$chat_ev=EduSysCacheMgr::getMessageDetailForm($comp_id,$v['chat_num']);
					if($v['vip_id'] != $vip_id){
						$cache->Rem(self::Z_MSG_TYPE.'M_'.$vip_id.'_T_N', $v['chat_num']);
						$cache->Add(self::Z_MSG_TYPE.'M_'.$vip_id.'_T_Y', strtotime($v['chat_date']), $v['chat_num']);
					}
					
				}
				
			}
			$this->ajaxReturn(array(
				'success'=>1,
				'data'=>$message,
				'chat'=>array_values($chat)
			));
		}elseif($type === 'F') {
			$data=EduSysCacheMgr::getMessageDetailFormVip($comp_id,$chat_num);
			$tovip['num']=count($data);
			foreach ($data as $k=>$v){
				$vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id, $v['recv_vip_id']);
				if($vip_info['vip_lvl'] == '02'){
					$user[$k]=$vip_info['vip_name'].$vip_info['course_name'];
				}else {
					$user[$k]=$vip_info['othername1'].$vip_info['familytree_name'];
				}
			}
			$this->ajaxReturn(array(
					'success'=>1,
					'data'=>$message,
					'vip'=>$user
			));
		}else {
			$this->ajaxReturn(array(
				'success'=>1,
				'data'=>$message,
			));
		}
	}
	//搜索
	public function messageSearch(){
		$content=getVar('content');
		$comp_id=$this->cur_comp_id;
		$type=getVar('type');
		$vip_id=$this->vip_id;
		if($type === 'F'){
			$chat_num=M('class_chat')->field('chat_num')->where(array('comp_id'=>$comp_id,'vip_id'=>$vip_id,'content'=>array('like','%'.$content.'%')))->select();
		}else {
			$chat_num=M('class_chat t1')
			->field('t1.chat_num')
			->join("left join t_class_chat_recv t2 on(t1.chat_num=t2.chat_num and t1.comp_id=t2.comp_id and t2.recv_vip_id=".$vip_id.")")
			->where(array('t1.comp_id'=>$comp_id,'t1.content'=>array('like','%'.$content.'%')))
			->select();
		}
		$list=array();
		foreach ($chat_num as $k=>$v){
			$message=EduSysCacheMgr::getMessageDetailForm($comp_id,$v['chat_num']);
			if(empty($message)){
				continue;
			}
			$orig_chat=EduSysCacheMgr::getMessageDetailForm($comp_id,$message['orig_chat_num']);
			$chat['orig_chat_num']=$message['orig_chat_num'];
			$chat['content']=$orig_chat['content'];
			$chat_user=EduSysCacheMgr::getUserVarDataByVid($comp_id, $orig_chat['vip_id']);
			$chat['headimgurl']=$chat_user['headimgurl'];
			$message['orig_chat']=$chat;
			$vip_info=EduSysCacheMgr::getUserVarDataByVid($comp_id, $message['vip_id']);
			if($vip_info['vip_lvl']  == '02'){
				$message['to_vipname']=$vip_info['vip_name'].$vip_info['course_name'].'老师';
			}else {
				$message['to_vipname']=$vip_info['othername1'].$vip_info['familytree_name'];
			}
			$message['to_vipid']=$vip_info['vip_id'];
			$message['to_vipheadimg']=$vip_info['headimgurl'];
			$img=EduSysCacheMgr::getMessageImg($comp_id,$v);
			$replyAttachment=$this->attachment($img);
			$message['video']=$replyAttachment['video'];
			$message['recording']=$replyAttachment['recording'];
			$message['picture']=$replyAttachment['picture'];
			array_push($list, $message);
		}
		$this->ajaxReturn(array(
				'success'=>1,
				'data'=>$list,
		));
	}
	
	
	//附件处理
	public function attachment($img){
		$picture=array();
		$recording=array();
		$video=array();
		foreach ($img as $key=>$val){
			if(empty($val['image_url'])){
				continue;
			}
			if($val['media_type'] == 'VIDEO'){
				if($val['is_compress']=='Y'){
					$url=C('COS_VIDEO_PUBLIC').$val['image_url'].'.f30.mp4';
				}else {
					$url=C('COS_VIDEO_PUBLIC').$val['image_url'];
				}
				$tempVideo = array(
						'image_url'=>$url,
						'image_num'=>$val['image_num'],
						'first_image_url'=>C('COS_PIC_PUBLIC').$val['first_image_url']
				);
				array_push($video, $tempVideo);
			}elseif ($val['media_type'] == 'RADIO'){
				$temprecod = array(
						'image_url'=>C('COS_PIC_PUBLIC').$val['image_url'],
						'image_num'=>$val['image_num'],
						'file_seconds'=>$val['file_seconds']
				);
				array_push($recording, $temprecod);
			}else {
				$tempImg = array(
						'image_url_src'=>C('COS_PIC_PUBLIC').$val['image_url'],
						'image_url'=>C('COS_PIC_PUBLIC').$val['image_url'],
						'image_url_s'=>C('COS_PIC_PUBLIC').$val['image_url'].PIC_STYLE_W100,
						'image_num'=>$val['image_num'],
				);
				array_push($picture, $tempImg);
			}
		}
		return array(
			'picture'=>$picture,
			'recording'=>$recording,
			'video'=>$video
		);
	}
	//附件删除
	public function attachmentDel(){
		$img_num=getVar('image_num');
		$chat_num=getVar('chat_num');
		$comp_id=$this->cur_comp_id;
		$del=M('class_chat_image')->where(array('comp_id'=>$comp_id,'chat_num'=>$chat_num,'image_num'=>$img_num))->delete();
		if(!$del){
			p_file('留言附件删除失败'.getDbError());
			$this->errorReturn('删除失败');
		}
		EduSysCacheMgr::reSetMessageImg($comp_id,$chat_num);
		$this->successReturn('删除成功');
	}
	
	//获取班级成员列表
	public function gradeClassStudents(){
		$comp_id=$this->cur_comp_id;
		$member=EduSysCacheMgr::getCompanyMembers($comp_id);
		if(empty($member)){
			$this->errorReturn('当前班级没有家长');
		}
		$data=array();
		foreach ($member as $k=>$v){
			if($v['vip_lvl']  == '02'){
				continue;
			}
			$vip=array();
			$vip=EduSysCacheMgr::getUserVarDataByVid($comp_id,$v['vip_id']);
			$temp=array(
				'vip_id'=>$v['vip_id'],
				'name'=>$vip['othername1'],
				'familytree_name'=>$vip['familytree_name'],
				'headimgurl'=>$vip['headimgurl']
			);
			array_push($data, $temp);
		}
		$this->ajaxReturn(array(
			'success'=>1,
			'data'=>$data
		));
	}
	//获取班级老师列表
	public function gradeClassTeacher(){
		$comp_id=$this->cur_comp_id;
		$member=EduSysCacheMgr::getCompanyMembers($comp_id);
		if(empty($member)){
			$this->errorReturn('当前班级没有家长');
		}
		$data=array();
		foreach ($member as $k=>$v){
			if($v['vip_lvl']  != '02'){
				continue;
			}
			$vip=array();
			$vip=EduSysCacheMgr::getUserVarDataByVid($comp_id,$v['vip_id']);
			$temp=array(
					'vip_id'=>$v['vip_id'],
					'name'=>$vip['vip_name'],
					'familytree_name'=>$vip['course_name'],
					'headimgurl'=>$vip['headimgurl']
			);
			array_push($data, $temp);
		}
		$this->ajaxReturn(array(
				'success'=>1,
				'data'=>$data
		));
	}
	//发送模板消息
	public function sendTemplateMessage($to_vipid,$msg_num,$title,$name,$message,$remark){
		$content=mb_substr($message,0,15,'UTF-8');
		$compid=$this->cur_comp_id;
		$appid=$this->app_id;
		$vip = EduSysCacheMgr::getUserVarDataByVid($compid, $to_vipid);
		if($vip['allow_tmplmsg']=='N'){
			return;
		}
	
		$setup_id=M('vip_platform_setup')->where(array('comp_id'=>$compid))->getField('setup_id');
		$get_openid=EduSysCacheMgr::getOpenid($compid,$setup_id,$to_vipid);
		$tmplUtils = new TmplMsgUtil($compid,$setup_id, 'memo_notice');
		$sErrmsg="";
		$isValid = $tmplUtils->checkValid($sErrmsg);
		if($isValid)
		{
	
			//准备经发送的内容
			$tmplDataBean = new TmplValueBean();
			$tmplDataBean->setTitle($title);
			$tmplDataBean->setKeyword1($name);
			$tmplDataBean->setKeyword2($content.'...');
			$tmplDataBean->setKeyword3(getdatetime(time()));
			$tmplDataBean->setRemark($remark);
			$tmplDataBean->setMiniAppid($appid);
			$tmplDataBean->setMiniPath('pages/index/index?type=comm&stype=S&chat_num='.$msg_num.'&vip_id='.$this->vip_id.'&compid='.$compid);
	
			$iMsgid="";
			$retstatus = $tmplUtils->sendTemplateMessageByOpenid($get_openid['openid'], $tmplDataBean, $iMsgid, $sErrmsg);
		}else{
			p_file('$sErrmsg001:'.$sErrmsg);
		}
		return;
	}
	
	
	
	
	
	
	
	
	
	
	
	
}