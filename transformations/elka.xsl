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
	     	<xsl:when test="local-name()='type'">
			   <xsl:choose>
					<xsl:when test=".='movingImage'"><type>MotionPicture</type></xsl:when>
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
