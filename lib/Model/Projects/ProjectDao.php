<?php

use Teams\TeamStruct;

class Projects_ProjectDao extends DataAccess_AbstractDao {
    const TABLE = "projects";

    protected static $auto_increment_fields = array('id');
    protected static $primary_keys = array('id');

    /**
     * @var string
     */
    protected static $_sql_for_project_unassignment = "
        UPDATE projects SET id_assignee = NULL WHERE id_assignee = :id_assignee and id_team = :id_team ;
    ";

    protected static $_sql_massive_self_assignment = "
        UPDATE projects SET id_assignee = :id_assignee , id_team = :personal_team WHERE id_team = :id_team ;
    ";

    /**
     * @param $project
     * @param $field
     * @param $value
     *
     * @return bool
     */
    public function updateField( $project, $field, $value ) {

        $sql = "UPDATE projects SET {$field} = :value WHERE id = :id ";

        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );

        $success = $stmt->execute( array(
            'value' => $value,
            'id' => $project->id
        ));

        if( $success ){
            $project->$field = $value;
        }

        return $project;

    }

    /**
     *
     * This update can easily become massive in case of long lived teams.
     * TODO: make this update chunked.
     *
     * @param Users_UserStruct $user
     * @return int
     */
    public function unassignProjects( TeamStruct $team, Users_UserStruct $user) {
        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( static::$_sql_for_project_unassignment ) ;
        $stmt->execute( [
                'id_assignee' => $user->uid,
                'id_team'     => $team->id
        ] ) ;

        return $stmt->rowCount();
    }

    /**
     * @param TeamStruct       $team
     * @param Users_UserStruct $user
     * @param TeamStruct       $personalTeam
     *
     * @return int
     */
    public function massiveSelfAssignment( TeamStruct $team, Users_UserStruct $user, TeamStruct $personalTeam ){
        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( static::$_sql_massive_self_assignment ) ;
        $stmt->execute( [
                'id_assignee'   => $user->uid,
                'id_team'       => $team->id,
                'personal_team' => $personalTeam->id
        ] );

        return $stmt->rowCount();
    }

    /**
     * @param int $id_job
     * @return Projects_ProjectStruct
     */
    static function findByJobId( $id_job ) {
        $conn = Database::obtain()->getConnection();
        $sql = "SELECT projects.* FROM projects " .
            " INNER JOIN jobs ON projects.id = jobs.id_project " .
            " WHERE jobs.id = :id_job " .
            " LIMIT 1 " ;
        $stmt = $conn->prepare( $sql );
        $stmt->execute( array('id_job' => $id_job ) );
        $stmt->setFetchMode( PDO::FETCH_CLASS, 'Projects_ProjectStruct' );
        return $stmt->fetch();
    }

    /**
     * @param $id_customer
     *
     * @return Projects_ProjectStruct[]
     */

    static function findByIdCustomer($id_customer) {
        $conn = Database::obtain()->getConnection();
        $sql = "SELECT projects.* FROM projects " .
                " WHERE id_customer = :id_customer ";

        $stmt = $conn->prepare( $sql );
        $stmt->execute( array('id_customer' => $id_customer ) );
        $stmt->setFetchMode( PDO::FETCH_CLASS, 'Projects_ProjectStruct' );
        return $stmt->fetchAll();
    }

    /**
     * @param $id
     * @return Projects_ProjectStruct
     */
    static function findById( $id ) {
       $conn = Database::obtain()->getConnection();
       $stmt = $conn->prepare( "SELECT * FROM projects WHERE id = ?");
       $stmt->execute( array( $id ) );
       $stmt->setFetchMode(PDO::FETCH_CLASS, 'Projects_ProjectStruct');
       return $stmt->fetch();
    }

    /**
     * @param $id
     * @param $password
     *
     * @return Projects_ProjectStruct
     * @throws \Exceptions\NotFoundError
     */
    static function findByIdAndPassword( $id, $password ) {
        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( "SELECT * FROM projects WHERE id = ? AND password = ? ");
        $stmt->execute( array( $id, $password ) );
        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Projects_ProjectStruct');
        $fetched =  $stmt->fetch();
        if ( !$fetched) {
            throw new Exceptions\NotFoundError();
        }
        return $fetched;
    }

    /**
     * Returns uncompleted chunks by project ID. Requires 'is_review' to be passed
     * as a param to filter the query.
     *
     * @return Chunks_ChunkStruct[]
     *
     */
    static function uncompletedChunksByProjectId( $id_project, $params=array() ) {
        $params = Utils::ensure_keys($params, array('is_review'));
        $is_review = $params['is_review'] || false;

        $sql = " SELECT jobs.* FROM jobs INNER join ( " .
            " SELECT j.id, j.password from jobs j
                LEFT JOIN chunk_completion_events events
                ON events.id_job = j.id and events.password = j.password
                LEFT JOIN chunk_completion_updates updates
                ON updates.id_job = j.id and updates.password = j.password
                AND updates.is_review = events.is_review
                WHERE
                (events.is_review = :is_review OR events.is_review IS NULL )
                AND
                ( j.id_project = :id_project AND events.id IS NULL )
                OR events.create_date < updates.last_translation_at
                GROUP BY j.id, j.password
            ) uncomplete ON jobs.id = uncomplete.id
                AND jobs.password = uncomplete.password
                AND jobs.id_project = :id_project
                ";

        \Log::doLog( $sql );

        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );
        $stmt->execute( array(
            'is_review' => $is_review,
            'id_project' => $id_project
        ) );

        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Chunks_ChunkStruct');

        return $stmt->fetchAll();

    }

    static function isGDriveProject( $id_project ) {
        $conn = Database::obtain()->getConnection();

        $sql =  "  SELECT count(f.id) "
                . "  FROM files f "
                . " INNER JOIN remote_files r "
                . "    ON f.id = r.id_file "
                . " WHERE f.id_project = :id_project "
                . "   AND r.is_original = 1 ";
        $stmt = $conn->prepare( $sql );
        $stmt->execute( array( 'id_project' => $id_project ) );
        $stmt->setFetchMode( PDO::FETCH_NUM );

        $result = $stmt->fetch();

        $countFiles = $result[ 0 ];

        if($countFiles > 0) {
            return true;
        }

        return false;
    }


    /**
     * @param $project_ids
     * @return array[]
     *
     */
    public function getRemoteFileServiceName( $project_ids ) {

        $project_ids = implode(', ', array_map(function($id) {
            return (int) $id ;
        }, $project_ids ));

        $sql = "SELECT id_project, c.service
          FROM files
          JOIN remote_files on files.id = remote_files.id_file
          JOIN connected_services c on c.id = connected_service_id
          WHERE id_project in ( $project_ids )
          GROUP BY id_project, c.service " ;

        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );
        $stmt->execute();
        $stmt->setFetchMode( PDO::FETCH_ASSOC );

        return $stmt->fetchAll();
    }



    protected function _buildResult( $array_result ) {

    }
}
