<?php
require __DIR__.'/../bootstrap.php';
use JPush\Model as M;
use JPush\JPushClient as JPush;
use JPush\Exception\APIConnectionException;
use JPush\Exception\APIRequestException;
use Carbon\Carbon;


$app->get('/init', function () use ($app) {
	Admin::create(array('username'=>'admin', 'password'=>Admin::salt('admin', 'admin')));
});

$app->get('/login', function () use ($app) {
	$app->render('login.php', array(
		'failed'=>false,
		'username'=>'',
	));
});

$app->post('/login', function() use ($app, $config) {
	$username = $app->request->post('username');
	$password = $app->request->post('password');
	$remember = $app->request->post('remember');
	if(!$username || !$password)
		return $app->response->redirect('/login');
	$admin = Admin::login($username, $password);
	if(!$admin) {
		return $app->render('login.php', array(
			'failed'=>true,
			'username'=>$username
		));
	}
	$app->setcookie('admin', $username, '30 days');
	return $app->redirect('/admin');
});

$app->get('/admin', function () use ($app) {
	return $app->redirect('/admin/dashboard');
});

$app->get('/admin/dashboard', function () use ($app) {
	return $app->render('dashboard.php', array(
		'active' => 'dashboard'
	));
});

$app->get('/admin/articles', function () use ($app) {
	$articles = Article::orderBy('created_at', 'desc')->take(10)->get();
	return $app->render('article_list.php', array(
		'active' => 'article',
		'articles' => $articles,
	));
});

$app->get('/admin/article/new', function () use ($app) {
	return $app->render('article_edit.php', array(
		'id' => false,
		'active' => 'article',
	));
});

$app->post('/admin/article', function () use ($app, $config) {
	$title = $app->request->post('title');
	$content = $app->request->post('content');
	$info = $app->request->post('info');
	$thumb = $app->request->post('thumb') ? 
		$app->request->post('thumb') : '/images/thumb_default.jpg';
	$article = Article::create(array(
		'title' => $title,
		'content' => $content,
		'info' => $info,
		'thumb' => $thumb,
	));
	if(!$config['development'])
		$article->author = Admin::find($app->getCookie('admin'));
	return $app->redirect('/admin/article/'.$article->id);
});

$app->get('/admin/article/:id/publish', function ($id) use ($app) {
	$article = Article::find($id);
	if(!$article)
		return $app->redirect('/admin/articles');
	$article->doPublish();
	return $app->redirect('/admin/articles');
});

$app->get('/admin/article/:id/cancel', function ($id) use ($app) {
	$article = Article::find($id);
	if(!$article)
		return $app->redirect('/admin/articles');
	$article->doCancel();
	return $app->redirect('/admin/articles');
});

$app->get('/admin/article/:id', function ($id) use ($app) {
	$article = Article::find($id);
	if(!$article)
		return $app->redirect('/admin/articles');
	return $app->render('article.php', array(
		'active' => 'article',
		'article' => $article
	));
});

$app->post('/admin/article/:id', function ($id) use ($app) {
	$article = Article::find($id);
	if(!$article)
		return $app->redirect('/admin/articles');
	$title = $app->request->post('title');
	$content = $app->request->post('content');
	$info = $app->request->post('info');
	$thumb = $app->request->post('thumb') ? 
		$app->request->post('thumb') : '/images/thumb_default.jpg';	
	$article->title = $title;
	$article->content = $content;
	$article->info = $info;
	$article->thumb = $thumb;
	$article->save();
	return $app->redirect('/admin/article/'.$article->id);
});

$app->get('/admin/article/:id/edit', function ($id) use ($app) {
	$article = Article::find($id);
	if(!$article)
		return $app->redirect('/admin/articles');
	return $app->render('article_edit.php', array(
		'id' => $article->id,
		'active' => 'article',
		'article' => $article,
	));
});

$app->get('/admin/push', function () use ($app) {
	$pushes = Push::with('article')->orderBy('created_at', 'desc')->take(20)->get();
	return $app->render('push.php', array(
		'active' => 'push',
		'pushes' => $pushes,
	));
});

$app->post('/admin/push', function () use ($app) {
	$message = $app->request->post('message');
	$title = $app->request->post('title') ? $app->request->post('title') : '日新网手机客户端';

	$jpush = new JPush('1d70641588d99d929ffd92b3', '87021dc315cba2ebef9ef5ac');
	$result = $jpush->push()
	    ->setPlatform(M\all)
    	->setAudience(M\all)
		->setNotification(M\notification(M\android($message, $title, 1, array("articleId"=>"1", "url"=>"http://www.ecjtu.net"))))
		->send();
	$push = Push::create(array(
		'msg_id' => $result->msg_id,
		'title' => $title,
		'message' => $message,
	));
	$push->save();
	return $app->redirect('/admin/push');
});

$app->get('/admin/settings', function () use ($app) {
	return $app->render('settings.php', array(
		'active' => 'settings'
	));
});

$app->post('/admin/image', function () use ($app) {
	if(!isset($_FILES['upload_file']))
		return ;
	$upload_dir = __DIR__.'/uploads/';
	$filename = strval(time()) . strval(rand(100,999)) . '.jpg';
	$file = $upload_dir . $filename;
	$origin_file_name = $_FILES['upload_file']['name'];
	if(!stristr($origin_file_name, '.') || end(explode('.', $origin_file_name)) != 'jpg') {
		echo json_encode(array(
			'success'=> false,
			'msg'=> '请使用jpg格式图片',
		));
		return ;
	}
	try{
		move_uploaded_file($_FILES['upload_file']['tmp_name'], $file);
	}catch(Exception $e){
		echo json_encode(array(
			'success'=> false,
			'msg'=> '文件权限不足',
		));
		return ;
	}
	echo json_encode(array(
		'success'=> true,
		'file_path'=> '/uploads/' . $filename,
	));
});

$app->get('/', function () use ($app) {

});

// API v1
$app->group('/api/v1', function () use ($app) {

	$app->get('/articles', function () use ($app) {
		$until = intval($app->request->get('until'));
		if($until && $until>0){
			$articles = Article::newest()
				->published()
				->where('id', '<', $until)
				->take(10)
				->get();
		}else{
			$articles = Article::published()->newest()->take(5)->get();
		}
		$articles = $articles->each(function($article){
			unset($article['content']);
			return $article;	
		});
		$articles = $articles->toArray();
		echo json_encode(array('status'=>200, 'count'=>count($articles), 'articles'=>$articles));
	});

	$app->get('/article/:id', function ($id) use ($app) {
		$article = Article::published()->with('Admin')->find($id);
		if(!$article){
			echo json_encode(array('status'=>404));
			return ;
		}
		$arr = $article->toArray();
		$arr['status'] = 200;
		echo json_encode($arr);
	});

});


$app->add(new AdminAuth());
$app->run();