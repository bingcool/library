<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class UserAccount extends SplBean
{
    protected $type;
    protected $user;
    protected $home;

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param $type
     */
    public function setType($type): void
    {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param mixed $user
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }


    /**
     * @return mixed
     */
    public function getHome()
    {
        return $this->home;
    }

    /**
     * @param $home
     */
    public function setHome($home): void
    {
        $this->home = $home;
    }
}