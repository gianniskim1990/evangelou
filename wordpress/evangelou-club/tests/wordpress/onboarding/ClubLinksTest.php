<?php
/**
 * STAGE-08 round 18, owner decision D31: the Club links with the member page AND the in-store join flow on (this suite:
 * EVC_CLUB_CUSTOMER_ENABLED + EVC_CLUB_ONBOARDING_ENABLED; the staff app is off). Fixed expected addresses for both
 * permalink forms and for a site installed in the /prometheus/ sub-folder, never recomputed by the code under test.
 */
final class ClubLinksTest extends EVC_WP_Test_Case {
    public function test_round18_d31_the_join_poster_and_member_links_follow_the_permalinks_and_the_install_folder(): void {
        $sub = static function () {
            return 'http://example.org/prometheus';
        };
        $expected = array(
            '' => array(
                'join' => 'http://example.org/prometheus/?evc_club_join=1',
                'poster' => 'http://example.org/prometheus/?evc_club_join=poster',
                'member' => 'http://example.org/prometheus/?evc_club_member=1',
            ),
            '/%postname%/' => array(
                'join' => 'http://example.org/prometheus/club/join/',
                'poster' => 'http://example.org/prometheus/club/join/poster/',
                'member' => 'http://example.org/prometheus/club-member/',
            ),
        );
        foreach ($expected as $structure => $want) {
            $this->set_permalink_structure($structure);
            add_filter('pre_option_home', $sub);
            add_filter('pre_option_siteurl', $sub);
            try {
                $links = EVC_Club_Links::staff();
            } finally {
                remove_filter('pre_option_home', $sub);
                remove_filter('pre_option_siteurl', $sub);
            }
            $this->assertSame(array('join', 'poster', 'member', 'member_login'), array_column($links, 'key'), 'no till link while the staff app is off');
            $by = array_column($links, 'url', 'key');
            $this->assertSame($want['join'], $by['join']);
            $this->assertSame($want['poster'], $by['poster']);
            $this->assertSame($want['member'], $by['member']);
            $this->assertSame('http://example.org/prometheus/wp-login.php?redirect_to=' . rawurlencode($want['member']), $by['member_login'], 'the login returns to the member page');
        }
    }
}
