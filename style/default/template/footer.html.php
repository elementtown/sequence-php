<?php if (empty($v['print'])): ?>
	<footer id="footer-wrap">
		<?php if (isset($v['core_copyright'])): ?>
			<div id="footer-copyright">
				<?= $l['COPYRIGHT'] ?> <?= $v['core_copyright_date'] ?> <?= $v['core_copyright_display'] ?>
			</div>
		<?php endif ?>
		<div id="footer-powered-by"><?= $l['POWERED_BY'] ?></div>
	</footer>
<?php endif ?>
<?php $f('footer_base.html') ?>
