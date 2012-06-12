<xsl:stylesheet version="1.0" 
xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
>
	 <xsl:output method="xml" indent="yes"/>
	
	 <xsl:template match="/|comment()|processing-instruction()">
	     <xsl:copy>
	       <xsl:apply-templates/>
	     </xsl:copy>
	 </xsl:template>
	 
	 <xsl:template match="*">
	     <xsl:choose>
	     	<xsl:when test="local-name()='format'">
			   <xsl:choose>
					<xsl:when test=".='1'"><type>Book</type></xsl:when>
					<xsl:when test=".='2'"><type>Map</type></xsl:when>
					<xsl:when test=".='3'"><type>SoundDisc</type></xsl:when>
					<xsl:when test=".='4'"><type>SoundCassette</type></xsl:when>
					<xsl:when test=".='6'"><type>SoundRecording</type></xsl:when>
					<xsl:when test=".='7'"><type>MusicalScore</type></xsl:when>
					<xsl:when test=".='9'"><type>Journal</type></xsl:when>
					<xsl:when test=".='a'"><type>Image</type></xsl:when>
					<xsl:when test=".='b'"><type>Slide</type></xsl:when>
					<xsl:when test=".='c'"><type>Drawing</type></xsl:when>
					<xsl:when test=".='d'"><type>CDROM</type></xsl:when>
					<xsl:when test=".='e'"><type>DVDROM</type></xsl:when>
					<xsl:when test=".='f'"><type>VideoCassette</type></xsl:when>
					<xsl:when test=".='g'"><type>DVD</type></xsl:when>
					<xsl:when test=".='h'"><type>BluRay</type></xsl:when>
					<xsl:when test=".='j'"><type>Microfilm</type></xsl:when>
					<xsl:when test=".='k'"><type>Manuscript</type></xsl:when>
					<xsl:when test=".='n'"><type>Braille</type></xsl:when>
					<xsl:when test=".='p'"><type>FloppyDisk</type></xsl:when>
					<xsl:when test=".='s'"><type>Software</type></xsl:when>
					<xsl:when test=".='q'"><type>PhysicalObject</type></xsl:when>
					<xsl:when test=".='r'"><type>BoardGame</type></xsl:when>
					<xsl:when test=".='w'"><type>DVDAudio</type></xsl:when>
					<xsl:when test=".='x'"><type>Electronic</type></xsl:when>
					<xsl:when test=".='z'"><type>eBook</type></xsl:when>
					<xsl:otherwise><type>Unknown</type></xsl:otherwise>
			   </xsl:choose>
	     	</xsl:when>
	      	<xsl:otherwise>
		    	<xsl:element name="{local-name()}">
					<xsl:apply-templates select="@*|node()"/>
		      	</xsl:element>
	      	</xsl:otherwise>
		</xsl:choose>
	 </xsl:template>
	 
	 <xsl:template match="@*">
	     <xsl:attribute name="{local-name()}">
	       <xsl:value-of select="."/>
	     </xsl:attribute>
	 </xsl:template>
	 
</xsl:stylesheet>
