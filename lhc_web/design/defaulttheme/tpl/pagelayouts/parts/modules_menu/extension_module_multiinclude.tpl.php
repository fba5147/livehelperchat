<?php if (erLhcoreClassUser::instance()->hasAccessTo('lhlivehelperchat','use_admin')) : ?>
<li class="nav-item"><a class="nav-link" href="<?php echo erLhcoreClassDesign::baseurl('social_network_monitoring/index')?>"><i class="material-icons">face</i><?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('pagelayout/pagelayout','Social Network Monitoring');?></a></li>
<?php endif; ?>
