<?php
use Carbon\Carbon;
class Article extends Illuminate\Database\Eloquent\Model {
	protected $fillable = ['title', 'content', 'info', 'thumb'];
	protected $hidden = ['admin_id'];

	public function scopeNewest ($query) {
		return $query->orderBy('created_at', 'desc');
	}

	public function scopePublished ($query) {
		return $query->where('published', true);
	}

	public function admin() {
		return $this->belongsTo('Admin');
	}

	public function doPublish() {
		$this->published = true;
		$this->published_at = Carbon::now();
		$this->save();
		return $this;
	}

	public function doCancel() {
		$this->published = false;
		$this->save();
		return $this;
	}
}