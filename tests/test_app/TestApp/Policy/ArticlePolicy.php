<?php
namespace TestApp\Policy;

use TestApp\Model\Entity\Article;

class ArticlePolicy
{

    /**
     * Create articles if you're an admin or author
     *
     * @param \Authorization\IdentityInterface $user
     * @return bool
     */
    public function canAdd($user)
    {
        return in_array($user['role'], ['admin', 'author']);
    }

    public function canEdit($user, Article $article)
    {
        if (in_array($user['role'], ['admin', 'author'])) {
            return true;
        }

        return $article->get('user_id') === $user['id'];
    }

    public function canModify($user, Article $article)
    {
        if (in_array($user['role'], ['admin', 'author'])) {
            return true;
        }

        return $article->get('user_id') === $user['id'];
    }

    /**
     * Delete only own articles or any if you're an admin
     *
     * @param \Authorization\IdentityInterface $user
     * @param Article $article
     * @return bool
     */
    public function canDelete($user, Article $article)
    {
        if ($user['role'] === 'admin') {
            return true;
        }

        return $user['id'] === $article->get('user_id');
    }

    /**
     * Scope method for index
     *
     * @param \Authorization\IdentityInterface $user
     * @param Article $article
     * @return bool
     */
    public function scopeIndex($user, Article $article)
    {
        $article->user_id = $user->getOriginalData()['id'];

        return $article;
    }

    /**
     * Testing that the article can be viewed if its public and no user is logged in
     *
     * This test "null" user cases
     *
     * @param \Authorization\IdentityInterface|null $user
     * @param Article $article
     * @return bool
     */
    public function canView($user, Article $article)
    {
        if ($article->get('visibility') !== 'public' && empty($user)) {
            return false;
        }

        return true;
    }

    /**
     * Testing that exceptions are properly handled
     *
     * @param \Authorization\IdentityInterface|null $user
     * @param Article $article
     * @return bool
     * @throws \Authorization\Exception\MissingIdentityException
     */
    public function canException($user, Article $article)
    {
        throw new \Authorization\Exception\MissingIdentityException();
    }
}
