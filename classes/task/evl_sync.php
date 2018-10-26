<?php

/**
 * evl_sync.php
 * 
 * Implementa procedimentos para sincronização de informações de uma instalação
 * da Escola Modelo com o servidor da EVL 
 * 
 */

namespace block_evl_sync\task;

require_once($CFG->dirroot.'/config.php');

include('httpful.phar');

class evl_sync extends \core\task\scheduled_task {      
    public function get_name() {
        // Shown in admin screens
        return get_string('evl_sync', 'block_evl_sync');
    }
                                                                     
    public function execute() {       

		$syncStartTime = 0;

		syncCourses($syncStartTime);
		syncEnrolments($syncStartTime);
		syncCertificates($syncStartTime);

	}
	
	/**
	 * Realiza procedimento de sincronização nos cursos criados e/ou atualizados
	 * que ainda não foram atualizados na EVL
	 */
	public function syncCourses($syncStartTime) {
		global $DB, $CFG;

		// Obtem todos os cursos pendentes de sincronização
		$sqlCourses = '
			SELECT c.id, c.timemodified, sc.time_sync
			FROM {course} c
				LEFT JOIN {ilb_sync_course} sc
					ON c.id = sc.course_id
			WHERE sc.course_id is null
				OR c.timemodified >= sc.time_sync
		';

		$cursos = $DB->get_records_sql($sqlCourses,array());

		// Processa cada cursos, gerando uma chamada individual ao web service
		foreach($cursos as $curso) {
			$indSuccess = sendCourseToWS($curso->id, true); // FIXME

			if($indSuccess) {
				$courseSyncData =  new stdClass();
				$courseSyncData->course_id = $curso->id;
				$courseSyncData->time_sync = $syncStartTime;

				$DB->insert_record('ilb_sync_course', $courseSyncData, false);
			}
		}
	}

	/**
	 * Realiza procedimento de sincronização de matrículas de usuários a cursos,
	 * as quais ainda não foram atualizadas na EVL
	 */
	public function syncEnrolments($syncStartTime) {
		
		// Obtem todas as matrículas pendentes de sincronização
		$sqlEnrolments = '
			SELECT *
			FROM mdl_user_enrolments ue
				LEFT JOIN mdl_ilb_sync_user_enrolments sue
					ON ue.id = sue.user_enrolment_id
			WHERE sue.user_enrolment_id is null
				OR ue.timemodified >= sue.time_sync		
		';

		$matriculas = $DB->get_records_sql($sqlEnrolments,array());

		// Processa cada matrícula, gerando uma chamada individual ao web service
		foreach($matriculas as $matricula) {
			$indSuccess = sendEnrolmentToWS($matricula->id, true); // FIXME

			if($indSuccess) {
				$enrolSyncData =  new stdClass();
				$enrolSyncData->course_id = $matricula->id;
				$enrolSyncData->time_sync = $syncStartTime;

				$DB->insert_record('ilb_sync_user_enrolments', $enrolSyncData, false);
			}
		}
	}
	
	/**
	 * Realiza procedimento de sincronização de certificados ainda não
	 * atualizados na EVL
	 */
	public function syncCertificates($syncStartTime) {
		
		// Identifica eventuais cursos não sincronizados
		$sqlCertificates = '
			SELECT c.id, u.username, ci.timecreated, gg.finalgrade, ci.code
			FROM (
				SELECT ci.timecreated, sc.time_sync, ci.code, ci.certificateid, ci.userid
				FROM mdl_certificate_issues ci
					LEFT JOIN mdl_ilb_sync_certificate sc
						ON ci.id = sc.certificate_id
				WHERE sc.time_sync is null
					OR ci.timecreated > sc.time_sync
			) ci
				JOIN mdl_certificate cert
					ON cert.id = ci.certificateid
				JOIN mdl_course c
					ON c.id = cert.course
				JOIN mdl_user u
					ON u.id = ci.userid
				JOIN mdl_grade_items gi
					ON gi.courseid = c.id
						AND gi.itemtype =  \'course\'
				JOIN mdl_grade_grades gg
					ON gg.itemid = gi.id
						AND gg.userid = ci.userid
		';

		$certificados = $DB->get_records_sql($sqlCertificates,array());

		// Processa cada certificado, gerando chamada ao web service 
		foreach ($certificados as $certificado) {
			$indSuccess = sendCertificateToWS($certificado->id, true); // FIXME

			if($indSuccess) {
				$certSyncData =  new stdClass();
				$certSyncData->course_id = $certificado->id;
				$certSyncData->time_sync = $syncStartTime;

				$DB->insert_record('ilb_sync_certificate', $certSyncData, false);
			}
		}
	}                                                                                                                               
	
	/**
	 * Método auxiliar para atualizar um curso na EVL
	 */
	function sendCourseToWS($courseID, $indUpdate = false) {

        $curso = $DB->get_record($event->objecttable,array('id'=>courseID));
        
		// Obtem a carga horária a partir do ID
		$idnumber = $curso->idnumber;
        preg_match("/\_CH([0-9]+)/", $idnumber, $x);
        $ch = $x[1];

		$obj = new StdClass();

		// Prepara e invoca método de inserção ou atualização, conforme for o caso
		if($indUpdate) {
			$uri = 'https://escolamodelows.interlegis.leg.br/api/v1/cursos/atualizar';

			$camposCurso = array(
				"name" => $curso->fullname,
				"url" => "", // fixme não deve haver esse campo
				"course_load" => $ch, // fixme como obter esse campo no Moodle
				"description" => $curso->summary,
				"logo" => "", // fixme não deve ter esse campo
				"school" => "SSL", // fixme criar campo no moodle
				"ead_id" => $curso->id
			);
	
			$obj->school = $CFG->school;
			$obj->course = $camposCurso;
			$json = json_encode($obj);
	
			$response = \Httpful\Request::patch($uri)
				->sendsJson()
				->body($json) 
				->send();

		} else {
			$uri = 'https://escolamodelows.interlegis.leg.br/api/v1/cursos/adicionar';

			$obj = new StdClass();

			$camposCurso = array(
				"name" => $curso->fullname,
				"url" => "", // fixme não deve haver esse campo
				"course_load" => $ch, // fixme como obter esse campo no Moodle
				"description" => $curso->summary,
				"logo" => "", // fixme não deve ter esse campo
				"ead_id" => $curso->id
			);
	
			$obj->course = $camposCurso;
			$obj->school = "SSL";
			$obj->category = "1";
			$json = json_encode($obj);
	
			$response = \Httpful\Request::post($uri)
				->sendsJson()
				->body($json)
				->send();
		}
	}

	/**
	 * Método auxiliar para atualizar uma matrícula na EVL
	 */
	function sendEnrolmentToWS($courseID, $indUpdate = false) {
	
	}


	/**
	 * Método auxiliar para atualizar um certificado na EVL
	 */
	function sendCertificateToWS($courseID, $indUpdate = false) {
	
	}


} 
?>