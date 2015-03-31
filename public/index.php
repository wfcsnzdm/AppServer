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
	$categories = Category::all();
	$categories = $categories->each(function($category){
		$category->checked = false;
	});
	return $app->render('article_edit.php', array(
		'id' => false,
		'active' => 'article',
		'categories' => $categories,
	));
});

$app->post('/admin/article', function () use ($app, $config) {
	$title = $app->request->post('title');
	$content = $app->request->post('content');
	$info = $app->request->post('info');
	$thumb = $app->request->post('thumb') ? 
		$app->request->post('thumb') : '/images/thumb_default.jpg';
	if(!$title || !$content || !$info || !$thumb)
		return $app->redirect('/admin/articles');
	if(mb_strlen($title) > 13 || mb_strlen($info) > 40)
		return $app->redirect('/admin/articles');
	$article = Article::create(array(
		'title' => $title,
		'content' => $content,
		'info' => $info,
		'thumb' => $thumb,
	));
	if(!$config['development'])
		$article->author = Admin::find($app->getCookie('admin'));
	$categories = $app->request->post('categories') ?: array();
	foreach($categories as $id => $category){
		if(Category::find($id))
			$article->addCategory(Category::find($id));
	}
	$article->save();
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
	if(!$title || !$content || !$info || !$thumb)
		return $app->redirect('/admin/articles');
	if(mb_strlen($title) > 13 || mb_strlen($info) > 40)
		return $app->redirect('/admin/articles');
	$article->title = $title;
	$article->content = $content;
	$article->info = $info;
	$article->thumb = $thumb;
	$categories = $app->request->post('categories');
	$article->categories()->detach();
	foreach($categories as $id => $category){
		if($category && Category::find($id))
			$article->addCategory(Category::find($id));
	}
	$article->save();
	return $app->redirect('/admin/article/'.$article->id);
});

$app->get('/admin/article/:id/edit', function ($id) use ($app) {
	$article = Article::find($id);
	$categories = Category::all();
	$categories = $categories->each(function($category) use ($article) {
		$ids = $article->categories()->lists('id');
		$category->checked = in_array($category->id, $ids) ? true : false;
	});
	if(!$article)
		return $app->redirect('/admin/articles');
	return $app->render('article_edit.php', array(
		'id' => $article->id,
		'active' => 'article',
		'article' => $article,
		'categories' => $categories,
	));
});

$app->get('/admin/article/:id/delete', function ($id) use ($app) {
	Article::destroy($id);
	return $app->redirect('/admin/articles');
});

$app->get('/admin/push', function () use ($app) {
	$pushes = Push::with('article')->orderBy('created_at', 'desc')->take(20)->get();
	return $app->render('push.php', array(
		'active' => 'push',
		'pushes' => $pushes,
	));
});

$app->post('/admin/push', function () use ($app, $config) {
	$message = $app->request->post('message');
	$title = $app->request->post('title') ? $app->request->post('title') : '日新网手机客户端';
	$aid = intval($app->request->post('aid'));
	if(!$message || !$aid)
		return $app->redirect('/admin/push');
	if(mb_strlen($title) > 10 || mb_strlen($message) > 16)
		return $app->redirect('/admin/push');
	if(!Article::find($id))
		return $app->redirect('/admin/push');
	$url = 'http://'.$config['domain'].'/api/v1/article/'.$aid.'/view';
	$jpush = new JPush('1d70641588d99d929ffd92b3', '87021dc315cba2ebef9ef5ac');
	$result = $jpush->push()
	    ->setPlatform(M\all)
    	->setAudience(M\all)
		->setNotification(M\notification(M\android($message, $title, 1, array("articleId"=>$aid, "url"=>$url))))
		->send();
	$push = Push::create(array(
		'msg_id' => $result->msg_id,
		'title' => $title,
		'message' => $message,
		'article_id' => $aid,
	));
	$push->save();
	return $app->redirect('/admin/push');
});

$app->get('/admin/category', function () use ($app) {
	$categories = Category::newest()->get();
	return $app->render('category.php', array(
		'active' => 'category',
		'categories' => $categories,
	));
});

$app->post('/admin/category/:id', function ($id) use ($app) {
	$text = $app->request->post('text');
	if(mb_strlen($text) > 6)
		return $app->redirect('/admin/category');
	$category = Category::find($id);
	if(!$text || !$category)
		return $app->redirect('/admin/category');
	$category->text = $text;
	$category->save();
	return $app->redirect('/admin/category');
});

$app->post('/admin/category', function () use ($app) {
	$text = $app->request->post('text');
	if(!$text)
		return $app->redirect('/admin/category');
	Category::create(array('text'=>$text));
	return $app->redirect('/admin/category');
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
	return $app->render('index.php');
});

// API v1
$app->group('/api/v1', function () use ($app) {

	$app->get('/index', function () use ($app) {
		$until = intval($app->request->get('until'));
		//分类ID为1的作为首页轮转图
		$image_articles = Category::find(1)->articles()
			->newest()
			->with('categories')
			->published()
			->take(5)
			->get();
		$normal_articles = Article::whereNotIn('id', $image_articles->lists('id'))
			->newest()
			->with('categories')
			->published();
		if($until && $until>0)
			$normal_articles = $normal_articles->until($until);
		$normal_articles = $normal_articles->take(10)->get();
		$image_articles = $image_articles->each(function($article){
			unset($article['content']);
			return $article;	
		});
		$normal_articles = $normal_articles->each(function($article){
			unset($article['content']);
			return $article;	
		});
		$image_articles = $image_articles->toArray();
		$normal_articles = $normal_articles->toArray();
		$return = array(
			'status' => 200,
			'slide_article' => array(
				'count' => count($image_articles),
				'articles' => $image_articles,
			), 
			'normal_article' => array(
				'count' => count($normal_articles),
				'articles' => $normal_articles,
			),
		);
		echo json_encode($return);
	});

	$app->get('/articles', function () use ($app) {
		$until = intval($app->request->get('until'));
		$articles = Article::newest()->with('categories')->published();
		if($until && $until>0)
			$articles = $articles->until($until);
		$articles = $articles->take(10)->get();
		$articles = $articles->each(function($article){
			unset($article['content']);
			return $article;	
		});
		$articles = $articles->toArray();
		echo json_encode(array('status'=>200, 'count'=>count($articles), 'articles'=>$articles));
	});

	$app->get('/article/:id/view', function ($id) use ($app) {
		$article = Article::published()->find($id);
		if(!$article)
			return $app->response->setStatus(404);
		$app->render('api_article_view.php', array(
			'content' => $article->content
		));
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